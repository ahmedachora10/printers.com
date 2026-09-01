<?php

namespace App\Console\Commands;

use App\Actions\System\BackupDatabaseAction;
use App\Support\Shell;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * نشر التطبيق على خادم الإنتاج — يُنفَّذ على الخادم نفسه.
 *
 * الترتيب مقصود: لا تُلمس قاعدة البيانات قبل أن يُغلق الموقع وتُؤخذ نسخة،
 * ولا يُفتح الموقع إلا بعد بناء الذاكرة المؤقتة. وإن سقطت خطوةٌ في الطريق
 * رُفع وضع الصيانة على كل حال، فلا يبقى الموقع مغلقاً بسبب عطلٍ عابر.
 *
 * الاستضافة مشتركة (cPanel)، فكل أداةٍ خارجية — git وcomposer وmysqldump —
 * تُلتمس قبل استعمالها، وإن غابت مضى النشر وأبلغ عنها.
 */
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
            $this->rebuildCaches();
            $this->restartWorkers();
        } catch (Throwable $e) {
            $failure = $e;
        } finally {
            if ($down) {
                $this->callSilent('up');
            }
        }

        $this->report($started, $failure);

        return $failure === null ? self::SUCCESS : self::FAILURE;
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

            $this->git($git, ['fetch', '--prune', 'origin', $branch]);
            $this->git($git, ['pull', '--ff-only', 'origin', $branch]);

            return $branch.' @ '.$this->git($git, ['rev-parse', '--short', 'HEAD']);
        });
    }

    private function installDependencies(): void
    {
        if ($this->option('skip-composer')) {
            $this->skipped('حزم composer', 'بطلبٍ من المُشغِّل');

            return;
        }

        $composer = Shell::locate('composer', [
            base_path('composer.phar'),
            '/opt/cpanel/composer/bin/composer',
            '/usr/local/bin/composer',
            '/usr/bin/composer',
        ]);

        if ($composer === null) {
            $this->skipped('حزم composer', 'composer غير متاح — حدّث vendor يدوياً إن تغيّرت الحزم');

            return;
        }

        $this->step('حزم composer', function () use ($composer): string {
            $home = storage_path('app/composer');
            File::ensureDirectoryExists($home);

            $command = str_ends_with($composer, '.phar') ? [PHP_BINARY, $composer] : [$composer];

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
                    '--no-dev',
                    '--prefer-dist',
                    '--no-progress',
                    '--no-interaction',
                    '--optimize-autoloader',
                ]);

            if ($result->failed()) {
                throw new RuntimeException('فشل composer install: '.trim($result->errorOutput() ?: $result->output()));
            }

            return '--no-dev، مع تحسين المُحمِّل التلقائي';
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

            $target = public_path('build');
            $previous = public_path('build.previous');

            File::deleteDirectory($previous);

            if (is_dir($target)) {
                rename($target, $previous);
            }

            rename($root, $target);
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

        if (! extension_loaded('zip') && $assets !== '') {
            $problems[] = 'امتداد zip غير مُفعَّل في PHP، فلا يمكن فكّ ملف الأصول.';
        }

        if (PHP_VERSION_ID < 80300) {
            $problems[] = 'نسخة PHP المستعملة '.PHP_VERSION.' والتطبيق يتطلب 8.3 فأعلى — راجع مسار مُفسِّر cron.';
        }

        return $problems;
    }

    private function environmentDetails(): void
    {
        $this->components->twoColumnDetail('البيئة', (string) config('app.env'));
        $this->components->twoColumnDetail('العنوان', (string) config('app.url'));
        $this->components->twoColumnDetail('مُفسِّر PHP', PHP_BINARY.' ('.PHP_VERSION.')');
        $this->components->twoColumnDetail('قاعدة البيانات', (string) config('database.default'));
        $this->newLine();
    }

    private function plan(): void
    {
        $this->components->warn('عرضٌ فقط — لن يُنفَّذ شيء.');

        $steps = [
            'إغلاق الموقع (down)' => ! $this->option('skip-maintenance'),
            'سحب الكود من origin' => ! $this->option('skip-pull'),
            'composer install --no-dev' => ! $this->option('skip-composer'),
            'نشر أصول الواجهة' => (string) ($this->option('assets') ?? '') !== '',
            'نسخة احتياطية لقاعدة البيانات' => ! $this->option('skip-backup'),
            'migrate --force' => ! $this->option('skip-migrations'),
            'إعادة بناء الذاكرة المؤقتة' => true,
            'queue:restart' => true,
            'فتح الموقع (up)' => ! $this->option('skip-maintenance'),
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

    private function report(float $started, ?Throwable $failure): void
    {
        $this->newLine();

        foreach ($this->notes as $note) {
            $this->components->twoColumnDetail('<fg=gray>'.$note.'</>');
        }

        if ($failure !== null) {
            $this->components->error('توقّف النشر: '.$failure->getMessage());
            $this->components->warn('الموقع أُعيد فتحه، والحالة كما قبل الخطوة الساقطة.');
        } else {
            $this->components->info('اكتمل النشر في '.$this->elapsed($started).'.');
        }

        $this->table(['الخطوة', 'الحالة', 'المدة'], $this->summary);
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
