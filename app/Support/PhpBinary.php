<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * أيُّ مُفسِّر PHP يُستعمل حين يُشغِّل النشرُ عمليةً خارجية.
 *
 * على cPanel اسمُ `php` في المسار قد يكون 7.4 أو 8.2 بينما الموقع يعمل على
 * 8.3، والكنية (alias) لا تُنجي: cron لا يقرأ ملفات الصدفة التفاعلية، فتقع
 * المهامُّ المجدولة على النسخة الخطأ وحدها. فالمسار يُثبَّت هنا صراحة.
 *
 * الترتيب: ما ضُبط في البيئة أولاً — فصاحب الخادم أدرى بخادمه — ثم مواضع
 * ea-php83 المعروفة، ثم ما يجده في المسار، وآخرها المُفسِّر الذي يعمل الآن.
 */
class PhpBinary
{
    /** أدنى نسخةٍ يقبلها التطبيق. */
    public const MINIMUM = 80300;

    /** @var array{path: string, version: string|null, versionId: int|null, source: string}|null */
    private static ?array $resolved = null;

    /**
     * المواضع المعتادة لـ PHP 8.3 على استضافات cPanel وCloudLinux.
     *
     * @var list<string>
     */
    private const CANDIDATES = [
        '/opt/cpanel/ea-php83/root/usr/bin/php',
        '/opt/cpanel/ea-php84/root/usr/bin/php',
        '/opt/alt/php83/usr/bin/php',
        '/usr/local/bin/ea-php83',
        '/usr/local/bin/php83',
    ];

    /**
     * المسار الذي تُشغَّل به العمليات الخارجية.
     */
    public static function path(): string
    {
        return self::describe()['path'];
    }

    /**
     * ما استقرّ عليه البحث، ومن أين جاء — للعرض وللتحقّق المسبق.
     *
     * @return array{path: string, version: string|null, versionId: int|null, source: string}
     */
    public static function describe(): array
    {
        return self::$resolved ??= self::discover();
    }

    /**
     * هل ضُبط مسارٌ في البيئة ثم تبيّن أنه لا يصلح؟ إعدادٌ صريحٌ خاطئ يُقال
     * ولا يُتجاوز بصمت، وإلا نُشر على مُفسِّرٍ غير الذي طُلب.
     */
    public static function misconfigured(): ?string
    {
        $configured = trim((string) config('deploy.php_binary'));

        if ($configured === '') {
            return null;
        }

        if (! self::executable($configured)) {
            return "DEPLOY_PHP_BINARY يشير إلى ملفٍّ غير موجود أو غير قابل للتنفيذ: {$configured}";
        }

        $versionId = self::versionId($configured);

        if ($versionId !== null && $versionId < self::MINIMUM) {
            return "DEPLOY_PHP_BINARY نسخته أقدم من المطلوب (8.3): {$configured}";
        }

        return null;
    }

    /**
     * السطر الذي يُنسخ ويُلصق حين يُشغَّل الأمر بمُفسِّرٍ خطأ — والذي يُكتب
     * في cron. المسار مطلقٌ في طرفيه، فلا يعتمد على مجلّدٍ ولا على كنية.
     */
    public static function artisanCommand(string $command = 'app:deploy'): string
    {
        return self::path().' '.base_path('artisan').' '.$command;
    }

    /** يُعاد الحساب في الاختبارات بعد تبديل الإعداد. */
    public static function forget(): void
    {
        self::$resolved = null;
    }

    /**
     * @return array{path: string, version: string|null, versionId: int|null, source: string}
     */
    private static function discover(): array
    {
        $configured = trim((string) config('deploy.php_binary'));

        if ($configured !== '' && self::executable($configured)) {
            return self::describeBinary($configured, 'DEPLOY_PHP_BINARY');
        }

        foreach (self::CANDIDATES as $candidate) {
            if (! self::executable($candidate)) {
                continue;
            }

            $versionId = self::versionId($candidate);

            // مُفسِّرٌ وجدناه بأنفسنا لا يُؤخذ إلا إن ثبتت صلاحيته؛ أما ما
            // ضُبط في البيئة فيُؤخذ كما هو ويُقال عنه في التحقّق المسبق.
            if ($versionId === null || $versionId >= self::MINIMUM) {
                return self::describeBinary($candidate, 'الاستضافة');
            }
        }

        foreach (['php83', 'ea-php83'] as $name) {
            $found = Shell::locate($name);

            if ($found !== null && (self::versionId($found) ?? self::MINIMUM) >= self::MINIMUM) {
                return self::describeBinary($found, 'المسار');
            }
        }

        return [
            'path' => PHP_BINARY,
            'version' => PHP_VERSION,
            'versionId' => PHP_VERSION_ID,
            'source' => 'المُفسِّر الحالي',
        ];
    }

    /**
     * @return array{path: string, version: string|null, versionId: int|null, source: string}
     */
    private static function describeBinary(string $path, string $source): array
    {
        $versionId = self::versionId($path);

        return [
            'path' => $path,
            'version' => self::version($path),
            'versionId' => $versionId,
            'source' => $source,
        ];
    }

    private static function executable(string $path): bool
    {
        return is_file($path) && is_executable($path);
    }

    private static function version(string $binary): ?string
    {
        return self::ask($binary, 'echo PHP_VERSION;');
    }

    private static function versionId(string $binary): ?int
    {
        $id = self::ask($binary, 'echo PHP_VERSION_ID;');

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * نسأل المُفسِّر عن نفسه. وإن كان proc_open ممنوعاً على الخادم عاد null،
     * فيُؤخذ المسار على ظاهره بدل أن يُرفض ما لا نستطيع فحصه.
     */
    private static function ask(string $binary, string $code): ?string
    {
        if (! Shell::available()) {
            return null;
        }

        try {
            $result = Process::timeout(15)->run([$binary, '-r', $code]);
        } catch (Throwable) {
            return null;
        }

        return $result->successful() ? trim($result->output()) : null;
    }
}
