<?php

namespace App\Http\Controllers;

use App\Actions\System\BackupDatabaseAction;
use App\Actions\System\StreamConsoleAction;
use App\Http\Requests\Deployment\RunTaskRequest;
use App\Models\Branch;
use App\Support\Bytecode;
use App\Support\ComposerBinary;
use App\Support\DeployAccess;
use App\Support\DeploySeeders;
use App\Support\DeployTasks;
use App\Support\SeederRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * الأوامر المفردة: تشغيل خطوةٍ واحدة دون النشر كلّه — هجرةٌ وحدها، أو زارعٌ
 * بعينه، أو مسح ذاكرةٍ مؤقتة بعد تعديلٍ يدوي.
 *
 * الإذن هو إذن شاشة النشر نفسه، والقفل هو قفلها نفسه: أمرٌ هنا لا يبدأ ونشرةٌ
 * تعمل، ولا نشرةَ تبدأ وأمرٌ هنا يعمل.
 */
class DeploymentTaskController extends Controller
{
    public function index(Request $request): Response
    {
        if (! DeployAccess::granted($request)) {
            return Inertia::render('deployment/unlock', [
                'appName' => (string) config('app.name'),
                // كالشاشة الأخرى: لا يُكشف للمجهول أنّ الخادم بلا مفتاح.
                'configured' => DeployAccess::revealsConfiguration($request)
                    ? DeployAccess::configured()
                    : true,
            ]);
        }

        return Inertia::render('deployment/commands', [
            'groups' => DeployTasks::GROUPS,
            'tasks' => DeployTasks::forDisplay(),
            'seeders' => DeploySeeders::all(),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
            'environment' => [
                'name' => (string) config('app.name'),
                'env' => (string) config('app.env'),
                'url' => (string) config('app.url'),
                'fakerInstalled' => DeploySeeders::fakerAvailable(),
                'composerAvailable' => ComposerBinary::available(),
            ],
            'standalone' => ! DeployAccess::isSuperAdmin($request),
        ]);
    }

    public function run(RunTaskRequest $request, StreamConsoleAction $console): StreamedResponse
    {
        $data = $request->validated();
        $key = $data['task'];
        $task = DeployTasks::find($key);

        return $console->handle(
            fn (OutputInterface $output): int => $this->execute($task, $data, $output),
            [
                'task' => $task['label'],
                'key' => $key,
                'ip' => $request->ip(),
                'via' => DeployAccess::isSuperAdmin($request) ? 'ui' : 'ui-token',
                'user' => $request->user()?->name,
            ],
            doneMarker: '== اكتمل النشر ==',
            failMarker: '== فشل النشر ==',
            busyMessage: 'هناك نشرٌ أو أمرٌ قيد التنفيذ الآن.',
        );
    }

    /**
     * @param  array{label: string, command: string, display: string, group: string, hint: string, destructive: bool, backup: bool, branch: bool}  $task
     * @param  array<string, mixed>  $data
     */
    private function execute(array $task, array $data, OutputInterface $output): int
    {
        $output->writeln('▶ '.$task['label'].'  ('.$task['display'].')');
        $output->writeln('');

        if ($task['backup']) {
            $this->backup($output);
        }

        return match ($task['command']) {
            'app:backup' => $this->backup($output),
            'db:seed' => $this->seed($data['seeders'] ?? [], $output),
            'app:composer-install' => $this->composer($output),
            'optimize', 'optimize:clear' => $this->optimize($task['command'], $output),
            'app:deploy' => Artisan::call('app:deploy', ['--dry-run' => true, '--force' => true], $output),
            default => Artisan::call($task['command'], $this->arguments($task, $data), $output),
        };
    }

    /**
     * @param  array{command: string, branch: bool}  $task
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function arguments(array $task, array $data): array
    {
        $arguments = match ($task['command']) {
            'migrate' => ['--force' => true],
            'down' => ['--retry' => 60],
            'queue:retry' => ['id' => ['all']],
            default => [],
        };

        if ($task['branch'] && ! empty($data['branch'])) {
            $arguments['--branch'] = $data['branch'];
        }

        return $arguments;
    }

    private function backup(OutputInterface $output): int
    {
        $path = app(BackupDatabaseAction::class)->handle(keep: 10);

        $output->writeln('نسخة احتياطية: '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
        $output->writeln('');

        return 0;
    }

    /**
     * @param  list<string>  $names
     */
    private function seed(array $names, OutputInterface $output): int
    {
        // زارعُ المصانع يحتاج faker، وهي حزمة تطوير. والتثبيت لا ينفع هذه
        // العملية — fake() تُعرَّف عند الإقلاع — فيُشغَّل الزارع بعده في
        // عمليةٍ جديدة تقرأ vendor كما صار.
        $fresh = false;

        if (DeploySeeders::needsDevInstall($names)) {
            $output->writeln('تثبيت حزم التطوير (faker مطلوبة لزارعٍ تجريبي)…');
            ComposerBinary::install(withDev: true);
            $output->writeln('تمّ.');
            $output->writeln('');

            $fresh = SeederRunner::canRunFresh();
        }

        foreach (DeploySeeders::resolve($names) as $seeder) {
            $output->writeln('زرع: '.$seeder['label'].($fresh ? ' (عملية جديدة)' : ''));

            if ($fresh) {
                SeederRunner::runFresh($seeder['class']);

                continue;
            }

            $status = Artisan::call('db:seed', ['--class' => $seeder['class'], '--force' => true], $output);

            if ($status !== 0) {
                return $status;
            }
        }

        return 0;
    }

    /**
     * بناءُ الذاكرة المؤقتة يكتب bootstrap/cache على القرص، وقد يبقى الخادم
     * يخدم النسخة المُصرَّفة من الملف القديم — فلا يُرى الجديدُ حتى تُبطَل.
     */
    private function optimize(string $command, OutputInterface $output): int
    {
        $status = Artisan::call($command, [], $output);

        if (Bytecode::flush()) {
            $output->writeln('أُبطلت الشفرة المُصرَّفة (opcache).');
        }

        return $status;
    }

    private function composer(OutputInterface $output): int
    {
        ComposerBinary::install();

        $output->writeln('ثُبّتت الحزم من ملف القفل (--no-dev).');

        return 0;
    }
}
