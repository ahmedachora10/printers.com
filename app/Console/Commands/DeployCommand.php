<?php

namespace App\Console\Commands;

use App\Actions\System\BackupDatabaseAction;
use App\Support\ComposerBinary;
use App\Support\DeploySeeders;
use App\Support\PhpBinary;
use App\Support\Shell;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use ZipArchive;

class DeployCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'app:deploy
        {--branch= : الفرع الذي يُسحب من المستودع (افتراضاً الفرع الحالي)}
        {--assets= : مسار أو رابط ملف build.zip الذي بنته GitHub Actions}
        {--skip-pull : تخطّي سحب الكود من المستودع}
        {--skip-composer : تخطّي تحديث حزم composer}
        {--skip-backup : تخطّي النسخة الاحتياطية — لا يُنصح به}
        {--skip-migrations : تخطّي هجرات قاعدة البيانات}
        {--seeders= : الزارعات المطلوبة مفصولةً بفاصلة (افتراضاً الأدوار والصلاحيات)}
        {--allow-demo-seeders : السماح بزارعات البيانات التجريبية — تخلق عملاء وفواتير وهمية}
        {--skip-seed : عدم تشغيل أي زارع}
        {--skip-health : تخطّي فحص الموقع بعد الفتح}
        {--no-rollback : عدم التراجع عن الكود والأصول إن سقطت خطوة}
        {--skip-maintenance : عدم إغلاق الموقع أثناء النشر}
        {--keep-backups=10 : عدد النسخ الاحتياطية المحتفظ بها}
        {--secret= : كلمة تجاوز وضع الصيانة (تُولَّد عشوائياً إن أُهملت)}
        {--dry-run : عرض الخطوات دون تنفيذها}
        {--force : تنفيذ دون سؤال تأكيدٍ في بيئة الإنتاج}';

    protected $description = 'نشر نسخةٍ جديدة من التطبيق على الخادم: سحب، حزم، أصول، نسخة احتياطية، هجرات، ذاكرة مؤقتة، طوابير';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $summary = [];

    /** @var list<string> */
    private array $notes = [];

    /**
     * ما يُتراجع عنه إن سقطت خطوة، مرتّباً كما وقع؛ ويُنفَّذ معكوساً.
     *
     * @var list<array{label: string, undo: callable(): void}>
     */
    private array $rollbacks = [];

    private ?string $backupPath = null;

    private ?string $composerBinary = null;

    private bool $codeReverted = false;

    /**
     * هل ثُبّتت حزم التطوير في هذه النشرة؟ إن كان، فالزارع يُشغَّل في عملية
     * جديدة: هذه العملية أقلعت وfaker غائبة، ولا تُعرَّف fake() بعد الإقلاع.
     */
    private bool $devDependenciesInstalled = false;

    public function handle(BackupDatabaseAction $backup): int
    {
        $this->newLine();
        $this->components->info('نشر '.config('app.name'));
        $this->environmentDetails();

        $problems = $this->preflight();

        // في العرض المجرّد نُبلّغ بالعوائق ولا نتوقف عندها، فالمقصود أن يرى
        // المُشغِّل الصورة كاملة: ما سيجري وما يعترضه.
        if ($this->option('dry-run')) {
            foreach ($problems as $problem) {
                $this->components->warn($problem);
            }

            $this->plan();

            return $problems === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->components->error($problem);
            }

            return self::FAILURE;
        }

        if (! $this->confirmToProceed('سيجري النشر على بيئة الإنتاج')) {
            return self::FAILURE;
        }

        $started = microtime(true);
        $secret = (string) ($this->option('secret') ?: Str::random(32));
        $down = false;
        $failure = null;

        try {
            if (! $this->option('skip-maintenance')) {
                $this->callSilent('down', ['--secret' => $secret, '--retry' => 60]);
                $down = true;
                $this->notes[] = 'رابط تجاوز الصيانة: '.rtrim((string) config('app.url'), '/').'/'.$secret;
            }

            $this->pullCode();
            $this->installDependencies();
            $this->publishAssets();
            $this->backupDatabase($backup);
            $this->runMigrations();
            $this->runSeeders();
            $this->rebuildCaches();
            $this->restartWorkers();
        } catch (Throwable $e) {
            $failure = $e;

            $this->rollback();
        } finally {
            if ($down) {
                $this->callSilent('up');
            }
        }

        $healthy = $this->healthCheck();

        $this->report($started, $failure, $healthy);

        return $failure === null && $healthy ? self::SUCCESS : self::FAILURE;
    }

    // ── الخطوات ──────────────────────────────────────────────────────────

    private function pullCode(): void
    {
        if ($this->option('skip-pull')) {
            $this->skipped('سحب الكود', 'بطلبٍ من المُشغِّل');

            return;
        }

        if (! is_dir(base_path('.git'))) {
            $this->skipped('سحب الكود', 'المجلد ليس مستودع git');

            return;
        }

        $git = Shell::locate('git', ['/usr/bin/git', '/usr/local/bin/git']);

        if ($git === null) {
            $this->skipped('سحب الكود', 'أداة git غير متاحة على الخادم');

            return;
        }

        $this->step('سحب الكود', function () use ($git): string {
            $branch = (string) ($this->option('branch') ?: $this->git($git, ['rev-parse', '--abbrev-ref', 'HEAD']));
            $before = $this->git($git, ['rev-parse', 'HEAD']);

            $this->git($git, ['fetch', '--prune', 'origin', $branch]);
            $this->git($git, ['pull', '--ff-only', 'origin', $branch]);

            $after = $this->git($git, ['rev-parse', 'HEAD']);

            if ($after !== $before) {
                $this->rollbacks[] = [
                    'label' => 'الكود',
                    'undo' => function () use ($git, $before): void {
                        $this->git($git, ['reset', '--hard', $before]);
                        $this->codeReverted = true;
                    },
                ];
            }

            return $branch.' @ '.substr($after, 0, 7).($after === $before ? ' (لا جديد)' : '');
        });
    }

    private function installDependencies(): void
    {
        if ($this->option('skip-composer')) {
            $this->skipped('حزم composer', 'بطلبٍ من المُشغِّل');

            return;
        }

        $composer = ComposerBinary::path();

        if ($composer === null) {
            $this->skipped('حزم composer', 'composer غير متاح — حدّث vendor يدوياً إن تغيّرت الحزم');

            return;
        }

        // زارعُ البيانات التجريبية يحتاج faker، وهي حزمة تطوير. فإن طُلب
        // زارعٌ منها ثُبّتت حزم التطوير في هذه النشرة وحدها.
        $withDev = ! $this->option('skip-seed') && DeploySeeders::needsDevInstall($this->seederNames());

        $this->step('حزم composer', function () use ($composer, $withDev): string {
            $this->composerInstall($composer, $withDev);
            $this->composerBinary = $composer;
            $this->devDependenciesInstalled = $withDev;

            return $withDev
                ? 'مع حزم التطوير — لأن زارعاً تجريبياً مطلوب'
                : '--no-dev، مع تحسين المُحمِّل التلقائي';
        });
    }

    private function publishAssets(): void
    {
        $source = (string) ($this->option('assets') ?? '');

        if ($source === '') {
            $this->skipped('أصول الواجهة', 'لا ملف مرفوع — نُبقي public/build كما هو');

            return;
        }

        $this->step('أصول الواجهة', function () use ($source): string {
            $archive = Str::startsWith($source, ['http://', 'https://'])
                ? $this->download($source)
                : $source;

            if (! is_file($archive)) {
                throw new RuntimeException("ملف الأصول غير موجود: {$archive}");
            }

            $extracted = public_path('build.new-'.now()->format('YmdHis'));

            $zip = new ZipArchive;

            if ($zip->open($archive) !== true) {
                throw new RuntimeException('تعذّر فتح ملف الأصول المضغوط.');
            }

            $zip->extractTo($extracted);
            $zip->close();

            $root = $this->manifestIn($extracted)
                ?? $this->manifestIn($extracted.'/build')
                ?? null;

            if ($root === null) {
                File::deleteDirectory($extracted);

                throw new RuntimeException('لم يُعثر على manifest.json داخل ملف الأصول.');
            }

            $this->stashBuild();

            rename($root, public_path('build'));
            File::deleteDirectory($extracted);

            if ($archive !== $source) {
                File::delete($archive);
            }

            return 'النسخة السابقة محفوظة في public/build.previous';
        });
    }

    private function backupDatabase(BackupDatabaseAction $action): void
    {
        if ($this->option('skip-backup')) {
            $this->skipped('نسخة احتياطية', 'بطلبٍ من المُشغِّل');

            return;
        }

        $this->step('نسخة احتياطية', function () use ($action): string {
            $path = $action->handle(keep: max(1, (int) $this->option('keep-backups')));
            $this->backupPath = $path;

            return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path)
                .' ('.$this->humanSize((int) filesize($path)).')';
        });
    }

    private function runMigrations(): void
    {
        if ($this->option('skip-migrations')) {
            $this->skipped('هجرات قاعدة البيانات', 'بطلبٍ من المُشغِّل');

            return;
        }

        $this->step('هجرات قاعدة البيانات', function (): string {
            $this->callSilent('migrate', ['--force' => true]);

            return 'migrate --force';
        });
    }

    /**
     * الزارعات المطلوبة بعد الهجرات. الافتراض زارع الأدوار والصلاحيات وحده:
     * الإصدار الجديد قد يأتي بصلاحيةٍ لم تُخلق بعد، فتبقى شاشتها مقفلةً في
     * وجه من يملكها، وهو firstOrCreate فلا يمسّ ما عدّله المدير.
     *
     * وما سواه يُطلب صراحةً بـ ‎--seeders=‎، وكلٌّ يُعدّ خطوةً على حدة ليُقرأ
     * في الجدول أيُّها جرى وأيُّها سقط.
     */
    private function runSeeders(): void
    {
        if ($this->option('skip-seed')) {
            $this->skipped('الزارعات', 'بطلبٍ من المُشغِّل');

            return;
        }

        foreach (DeploySeeders::resolve($this->seederNames()) as $seeder) {
            // التحقّق المسبق يمنع هذا أصلاً؛ وهذا سياجٌ ثانٍ، فالسقوط هنا
            // يقع بعد الهجرات حيث لا تراجع.
            if (! $seeder['runnable']) {
                $this->skipped('زرع: '.$seeder['label'], DeploySeeders::unavailableReason());

                continue;
            }

            $this->step('زرع: '.$seeder['label'], function () use ($seeder): string {
                $fresh = $this->seedInFreshProcess($seeder['class']);

                return $seeder['name']
                    .($seeder['demo'] ? ' — بيانات تجريبية' : '')
                    .($fresh ? ' (عملية جديدة)' : '');
            });
        }
    }

    /**
     * تشغيل الزارع. الأصل أن يُنادى في هذه العملية، إلا أن تكون حزم التطوير
     * قد ثُبّتت قبل قليل: هذه العملية أقلعت وfaker غائبة، ودالّة fake() لا
     * تُعرَّف إلا عند الإقلاع وبشرط وجود ‎\Faker\Factory‎ — فلا يُصلحها
     * تثبيتٌ بعده، ولا يرى مُحمِّلُها الملفات الجديدة. فيُفتح لها مُفسِّرٌ
     * جديد يقرأ vendor كما صار.
     *
     * @return bool هل جرى في عمليةٍ جديدة؟
     */
    private function seedInFreshProcess(string $class): bool
    {
        if (! $this->devDependenciesInstalled || ! Shell::available()) {
            $this->callSilent('db:seed', ['--class' => $class, '--force' => true]);

            return false;
        }

        $result = Process::path(base_path())
            ->timeout(1800)
            ->run([PhpBinary::path(), base_path('artisan'), 'db:seed', '--class='.$class, '--force']);

        if ($result->failed()) {
            throw new RuntimeException('فشل الزارع '.class_basename($class).': '.trim($result->errorOutput() ?: $result->output()));
        }

        return true;
    }

    /**
     * إعادة تركيب الأمر بخياراته كما كُتبت، ليُنسخ السطر كما هو بدل أن
     * يُعاد تذكّر ما مُرِّر. الخيارات العامة تُطرح، فهي ليست من الطلب.
     */
    private function reinvocation(): string
    {
        $global = ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env', 'silent'];

        $parts = ['app:deploy'];

        foreach ($this->options() as $name => $value) {
            if (in_array($name, $global, true) || $value === false || $value === null || $value === '') {
                continue;
            }

            $parts[] = $value === true ? "--{$name}" : "--{$name}=".escapeshellarg((string) $value);
        }

        return implode(' ', $parts);
    }

    private function devInstallPlanned(): bool
    {
        if ($this->option('skip-seed')) {
            return false;
        }

        try {
            return DeploySeeders::needsDevInstall($this->seederNames());
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private function plannedSeeders(): string
    {
        if ($this->option('skip-seed')) {
            return 'لا شيء';
        }

        try {
            return collect(DeploySeeders::resolve($this->seederNames()))
                ->map(fn (array $seeder): string => $seeder['label'].($seeder['demo'] ? ' ⚠' : ''))
                ->implode('، ');
        } catch (InvalidArgumentException) {
            return 'غير معروفة';
        }
    }

    /**
     * @return list<string>
     */
    private function seederNames(): array
    {
        $requested = trim((string) ($this->option('seeders') ?? ''));

        if ($requested === '') {
            return DeploySeeders::DEFAULT;
        }

        return array_values(array_filter(array_map('trim', explode(',', $requested))));
    }

    private function rebuildCaches(): void
    {
        $this->step('الذاكرة المؤقتة', function (): string {
            $this->callSilent('optimize:clear');

            if (! file_exists(public_path('storage'))) {
                try {
                    $this->callSilent('storage:link');
                } catch (Throwable) {
                    // الرابط الرمزي ممنوعٌ على بعض الاستضافات، والملفات هنا خاصة أصلاً.
                }
            }

            $this->callSilent('optimize');

            return 'config، routes، views، events';
        });
    }

    private function restartWorkers(): void
    {
        $this->step('طوابير المهام', function (): string {
            $this->callSilent('queue:restart');

            return 'أُشير للعمّال بالخروج — يلتقط cron الكود الجديد';
        });
    }

    // ── التراجع والفحص ───────────────────────────────────────────────────

    /**
     * إعادة ما يمكن ردّه: الأصول ثم الكود، معكوساً كما وقع. وما لا يُردّ —
     * الهجرات — يُقال فيه الحق: هنا نسختك، وهذا أمر استعادتها، والقرار لك.
     * استعادةٌ تلقائية لقاعدةٍ عاشت دقائق تحت الإصدار الجديد قد تمحو بيعاً
     * جرى فيها، وذاك أفدح من عطلٍ يُصلَح بيد.
     */
    private function rollback(): void
    {
        if ($this->option('no-rollback')) {
            $this->notes[] = 'التراجع موقوفٌ بطلب المُشغِّل — الشجرة على حالها بعد السقوط.';

            return;
        }

        if ($this->rollbacks === []) {
            $this->notes[] = 'لا شيء يُتراجع عنه — لم يتغيّر كودٌ ولا أصول قبل السقوط.';

            return;
        }

        $this->newLine();
        $this->components->warn('التراجع عمّا تغيّر…');

        foreach (array_reverse($this->rollbacks) as $entry) {
            try {
                ($entry['undo'])();
                $this->components->twoColumnDetail('استرجاع '.$entry['label'], '<fg=green>تم</>');
            } catch (Throwable $e) {
                $this->components->twoColumnDetail('استرجاع '.$entry['label'], '<fg=red>تعذّر</>');
                $this->notes[] = 'تعذّر استرجاع '.$entry['label'].': '.$e->getMessage();
            }
        }

        // رجع الكود إلى إصداره السابق، وvendor ما زال على قفل الإصدار الجديد.
        if ($this->codeReverted && $this->composerBinary !== null) {
            try {
                $this->composerInstall($this->composerBinary);
                $this->components->twoColumnDetail('استرجاع حزم composer', '<fg=green>تم</>');
            } catch (Throwable $e) {
                $this->components->twoColumnDetail('استرجاع حزم composer', '<fg=red>تعذّر</>');
                $this->notes[] = 'vendor لا يطابق الكود المُسترجَع: '.$e->getMessage();
            }
        }

        // الذاكرة المؤقتة بُنيت — أو لم تُبنَ — على كودٍ غير الذي على القرص الآن.
        try {
            $this->callSilent('optimize:clear');
            $this->callSilent('optimize');
        } catch (Throwable) {
            $this->notes[] = 'تعذّرت إعادة بناء الذاكرة المؤقتة بعد التراجع — نفّذ php artisan optimize يدوياً.';
        }

        if (! $this->option('skip-migrations') && $this->backupPath !== null) {
            $this->notes[] = 'الهجرات لا تُردّ تلقائياً. النسخة: '.$this->backupPath;
            $this->notes[] = 'للاستعادة عند الحاجة: mysql -u USER -p DB < '.$this->backupPath.' (فُكّ الضغط أولاً إن كانت مضغوطة)';
        }
    }

    /**
     * فحصٌ بعد الفتح: قاعدةٌ تُجيب وصفحةٌ تردّ 200. تعذُّر الوصول من الخادم
     * إلى عنوانه العام شائعٌ على الاستضافات المشتركة، فذاك تنبيهٌ لا حكم.
     */
    private function healthCheck(): bool
    {
        if ($this->option('skip-health')) {
            $this->skipped('فحص الموقع', 'بطلبٍ من المُشغِّل');

            return true;
        }

        $healthy = true;

        try {
            DB::connection()->select('select 1');
            $this->components->twoColumnDetail('فحص قاعدة البيانات', '<fg=green>تُجيب</>');
        } catch (Throwable $e) {
            $this->components->twoColumnDetail('فحص قاعدة البيانات', '<fg=red>لا تُجيب</>');
            $this->notes[] = 'قاعدة البيانات لا تُجيب بعد النشر: '.$e->getMessage();
            $healthy = false;
        }

        $url = rtrim((string) config('app.url'), '/').'/up';

        try {
            $status = Http::timeout(30)->withoutVerifying()->get($url)->status();
        } catch (Throwable $e) {
            $this->components->twoColumnDetail('فحص '.$url, '<fg=yellow>تعذّر الوصول</>');
            $this->notes[] = 'لم يبلغ الخادمُ عنوانَه العام — افحص الموقع من متصفّحك: '.$e->getMessage();

            return $healthy;
        }

        if ($status >= 200 && $status < 300) {
            $this->components->twoColumnDetail('فحص '.$url, '<fg=green>'.$status.'</>');

            return $healthy;
        }

        $this->components->twoColumnDetail('فحص '.$url, '<fg=red>'.$status.'</>');
        $this->notes[] = 'الموقع مفتوحٌ لكنه يردّ '.$status.' — راجع storage/logs فوراً.';

        return false;
    }

    // ── التحقق قبل البدء ─────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function preflight(): array
    {
        $problems = [];

        if ((string) config('app.key') === '') {
            $problems[] = 'APP_KEY فارغ في ملف البيئة.';
        }

        foreach (['storage', 'storage/framework', 'storage/logs', 'bootstrap/cache', 'public'] as $path) {
            if (! is_writable(base_path($path))) {
                $problems[] = "المجلد غير قابل للكتابة: {$path}";
            }
        }

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $problems[] = 'تعذّر الاتصال بقاعدة البيانات: '.$e->getMessage();
        }

        $assets = (string) ($this->option('assets') ?? '');

        if ($assets === '' && $this->manifestIn(public_path('build')) === null) {
            $problems[] = 'لا توجد أصول مبنية في public/build — مرّر ‎--assets=‎ لملف build.zip من GitHub Actions.';
        }

        // زارعُ بياناتٍ تجريبية على فرعٍ عامل يخلق عملاء وفواتير وهمية بين
        // الحقيقية، ولا يُميَّز بعدها. فلا يمرّ إلا بطلبٍ صريح، ويُقال في
        // العرض المجرّد قبل أن يُنفَّذ.
        if (! $this->option('skip-seed')) {
            try {
                $demo = collect(DeploySeeders::resolve($this->seederNames()))
                    ->where('demo', true)
                    ->pluck('label');

                if ($demo->isNotEmpty() && ! $this->option('allow-demo-seeders')) {
                    $problems[] = 'زارعات بياناتٍ تجريبية مطلوبة ('.$demo->implode('، ').') — أضف ‎--allow-demo-seeders‎ إن كنت تقصدها فعلاً.';
                }

                // ولو أذِن بها: لا مصانع بلا faker. وغيابها يُعالَج بتثبيت
                // حزم التطوير، فالمانع أن يتعذّر التثبيت نفسه — تخطّي خطوة
                // composer أو غيابه عن الخادم. والوقوف هنا قبل أن يُغلق
                // الموقع أرحم من الفشل بعد الهجرات.
                $canInstall = ! $this->option('skip-composer') && ComposerBinary::available();
                $blocked = DeploySeeders::blocked($this->seederNames(), $canInstall);

                if ($blocked !== []) {
                    $problems[] = 'زارعات متعذّرة على هذا الخادم ('.implode('، ', $blocked).'): '
                        .($this->option('skip-composer')
                            ? 'حزم التطوير غير مثبّتة، وخطوة composer متخطّاة فلا تُثبَّت.'
                            : DeploySeeders::unavailableReason());
                }
            } catch (InvalidArgumentException $e) {
                $problems[] = $e->getMessage();
            }
        }

        if (! extension_loaded('zip') && $assets !== '') {
            $problems[] = 'امتداد zip غير مُفعَّل في PHP، فلا يمكن فكّ ملف الأصول.';
        }

        if (PHP_VERSION_ID < PhpBinary::MINIMUM) {
            $problems[] = 'نسخة PHP المستعملة '.PHP_VERSION.' والتطبيق يتطلب 8.3 فأعلى.';
            $problems[] = 'أعِد التشغيل بالمُفسِّر الصحيح: '.PhpBinary::artisanCommand($this->reinvocation());
        }

        if (($misconfigured = PhpBinary::misconfigured()) !== null) {
            $problems[] = $misconfigured;
        }

        return $problems;
    }

    private function environmentDetails(): void
    {
        $this->components->twoColumnDetail('البيئة', (string) config('app.env'));
        $this->components->twoColumnDetail('العنوان', (string) config('app.url'));
        $this->components->twoColumnDetail('مُفسِّر PHP', PHP_BINARY.' ('.PHP_VERSION.')');

        $php = PhpBinary::describe();

        // نُظهر المُفسِّر المختار حين يخالف الذي يعمل الآن، فذاك موضع اللبس:
        // أمرٌ يعمل على نسخة ويُشغِّل composer بأخرى.
        if ($php['path'] !== PHP_BINARY) {
            $this->components->twoColumnDetail(
                'مُفسِّر العمليات الفرعية',
                $php['path'].($php['version'] !== null ? ' ('.$php['version'].')' : '').' — '.$php['source']
            );
        }
        $this->components->twoColumnDetail('قاعدة البيانات', (string) config('database.default'));
        $this->newLine();
    }

    private function plan(): void
    {
        $this->components->warn('عرضٌ فقط — لن يُنفَّذ شيء.');

        $assets = (string) ($this->option('assets') ?? '');

        $steps = [
            'إغلاق الموقع (down)' => ! $this->option('skip-maintenance'),
            'سحب الكود من origin' => ! $this->option('skip-pull'),
            'composer install --no-dev' => ! $this->option('skip-composer') && ! $this->devInstallPlanned(),
            'composer install مع حزم التطوير (لأجل زارعٍ تجريبي)' => ! $this->option('skip-composer') && $this->devInstallPlanned(),
            'نشر أصول الواجهة المرفوعة' => $assets !== '',
            'نسخة احتياطية لقاعدة البيانات' => ! $this->option('skip-backup'),
            'migrate --force' => ! $this->option('skip-migrations'),
            'الزارعات: '.$this->plannedSeeders() => ! $this->option('skip-seed'),
            'إعادة بناء الذاكرة المؤقتة' => true,
            'queue:restart' => true,
            'فتح الموقع (up)' => ! $this->option('skip-maintenance'),
            'فحص الموقع بعد الفتح' => ! $this->option('skip-health'),
            'التراجع إن سقطت خطوة' => ! $this->option('no-rollback'),
        ];

        foreach ($steps as $label => $enabled) {
            $this->components->twoColumnDetail($label, $enabled ? '<fg=green>سيُنفَّذ</>' : '<fg=gray>متخطّى</>');
        }
    }

    // ── أدوات مساعدة ─────────────────────────────────────────────────────

    private function step(string $title, callable $callback): void
    {
        $started = microtime(true);

        try {
            $note = (string) $callback();
        } catch (Throwable $e) {
            $this->summary[] = [$title, '<fg=red>فشل</>', $this->elapsed($started)];
            $this->components->twoColumnDetail($title, '<fg=red>فشل</>');

            throw $e;
        }

        $this->summary[] = [$title, '<fg=green>تم</>', $this->elapsed($started)];
        $this->components->twoColumnDetail($title, '<fg=green>تم</> <fg=gray>'.$note.'</>');
    }

    private function skipped(string $title, string $reason): void
    {
        $this->summary[] = [$title, '<fg=yellow>متخطّى</>', '—'];
        $this->components->twoColumnDetail($title, '<fg=yellow>متخطّى</> <fg=gray>'.$reason.'</>');
    }

    /**
     * تنحية public/build القائم إلى build.previous، وتسجيل ردّه إن سقط النشر.
     * ننحّيه ولا نحذفه، فهو النسخة العاملة الوحيدة حتى تنجح التي بعدها.
     */
    private function stashBuild(): void
    {
        $target = public_path('build');
        $previous = public_path('build.previous');

        File::deleteDirectory($previous);

        if (! is_dir($target)) {
            return;
        }

        rename($target, $previous);

        $this->rollbacks[] = [
            'label' => 'أصول الواجهة',
            'undo' => function () use ($target, $previous): void {
                File::deleteDirectory($target);
                rename($previous, $target);
            },
        ];
    }

    private function composerInstall(string $composer, bool $withDev = false): void
    {
        $home = storage_path('app/composer');
        File::ensureDirectoryExists($home);

        // composer.phar يُشغَّل بمُفسِّرٍ صريح: `php` في المسار على cPanel قد
        // يكون نسخةً لا تُقلع بها حزم المشروع أصلاً.
        $command = str_ends_with($composer, '.phar') ? [PhpBinary::path(), $composer] : [$composer];

        $result = Process::path(base_path())
            ->timeout(1200)
            ->env([
                'COMPOSER_HOME' => $home,
                'COMPOSER_MEMORY_LIMIT' => '-1',
                'COMPOSER_NO_INTERACTION' => '1',
            ])
            ->run([
                ...$command,
                'install',
                ...($withDev ? [] : ['--no-dev']),
                '--prefer-dist',
                '--no-progress',
                '--no-interaction',
                '--optimize-autoloader',
            ]);

        if ($result->failed()) {
            throw new RuntimeException('فشل composer install: '.trim($result->errorOutput() ?: $result->output()));
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(string $binary, array $arguments): string
    {
        $result = Process::path(base_path())->timeout(600)->run([$binary, ...$arguments]);

        if ($result->failed()) {
            throw new RuntimeException('فشل git '.$arguments[0].': '.trim($result->errorOutput() ?: $result->output()));
        }

        return trim($result->output());
    }

    private function download(string $url): string
    {
        $path = storage_path('app/deploy-assets-'.now()->format('YmdHis').'.zip');

        Http::timeout(300)->sink($path)->get($url)->throw();

        return $path;
    }

    private function manifestIn(string $directory): ?string
    {
        if (! is_dir($directory)) {
            return null;
        }

        $found = is_file($directory.'/manifest.json') || is_file($directory.'/.vite/manifest.json');

        return $found ? $directory : null;
    }

    private function report(float $started, ?Throwable $failure, bool $healthy): void
    {
        $this->newLine();

        foreach ($this->notes as $note) {
            $this->components->twoColumnDetail('<fg=gray>'.$note.'</>');
        }

        if ($failure !== null) {
            $this->components->error('توقّف النشر: '.$failure->getMessage());
            $this->components->warn($this->option('no-rollback')
                ? 'الموقع أُعيد فتحه، والشجرة كما تركتها الخطوة الساقطة — التراجع موقوف.'
                : 'الموقع أُعيد فتحه، وأُرجع الكود والأصول إلى ما كانا عليه.');
        } elseif (! $healthy) {
            $this->components->error('اكتملت الخطوات لكنّ فحص الموقع لم يمرّ — راجع التنبيهات أعلاه.');
        } else {
            $this->components->info('اكتمل النشر في '.$this->elapsed($started).'.');
        }

        $this->table(['الخطوة', 'الحالة', 'المدة'], $this->summary);

        $this->log($started, $failure, $healthy);
    }

    /**
     * أثرٌ باقٍ في storage/logs: المخرجات تُطبع لمن أطلق النشر ثم تذهب، وأول
     * ما يُسأل عنه بعد عطلٍ هو متى نُشر وما الذي جرى فيه.
     */
    private function log(float $started, ?Throwable $failure, bool $healthy): void
    {
        $context = [
            'env' => config('app.env'),
            'duration' => $this->elapsed($started),
            'healthy' => $healthy,
            'steps' => array_map(
                fn (array $row): string => $row[0].': '.strip_tags($row[1]).' ('.$row[2].')',
                $this->summary
            ),
            'notes' => $this->notes,
        ];

        if ($failure === null) {
            Log::info($healthy ? 'اكتمل النشر' : 'اكتمل النشر مع فشل الفحص', $context);

            return;
        }

        Log::error('فشل النشر: '.$failure->getMessage(), $context + [
            'exception' => $failure::class,
            'file' => $failure->getFile().':'.$failure->getLine(),
        ]);
    }

    private function elapsed(float $from): string
    {
        return number_format(microtime(true) - $from, 1).' ث';
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return number_format($bytes / (1024 ** $power), 1).' '.$units[$power];
    }
}
