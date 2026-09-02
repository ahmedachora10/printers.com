<?php

namespace App\Http\Controllers;

use App\Actions\System\StreamDeployAction;
use App\Http\Requests\Deployment\RunDeploymentRequest;
use App\Support\ComposerBinary;
use App\Support\DeployAccess;
use App\Support\DeployPreferences;
use App\Support\DeploySeeders;
use App\Support\PhpBinary;
use App\Support\Shell;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * شاشة النشر: يختار السوبر أدمن ما يُنفَّذ ثم يرى المخرجات تُكتب أمامه.
 *
 * الشاشة تُحرّك الأمر نفسه الذي يعمل من سطر الأوامر، لا نسخةً منه، فما يُقرأ
 * هنا هو ما يجري هناك. وكلُّ ما تُرسله الشاشة يُتحقَّق منه على الخادم: الشاشة
 * تُيسّر الاختيار ولا تملك الإذن.
 */
class DeploymentController extends Controller
{
    public function index(Request $request): Response
    {
        // من لا إذن له لا يُطرد: تُعرض عليه شاشة المفتاح، فهي المقصودة به.
        if (! DeployAccess::granted($request)) {
            return Inertia::render('deployment/unlock', [
                'appName' => (string) config('app.name'),
                'configured' => DeployAccess::configured(),
            ]);
        }

        return Inertia::render('deployment/index', [
            'environment' => $this->environment(),
            'seeders' => DeploySeeders::all(),
            'preferences' => DeployPreferences::load(),
            'history' => $this->history(),
            // حاملُ المفتاح قد يكون زائراً بلا حساب، ولا سبيل إلى قالبٍ يعرض
            // قائمةَ تطبيقٍ لم يدخله؛ ولا داعي أن يرى خارطته أصلاً.
            'standalone' => ! DeployAccess::isSuperAdmin($request),
            'unlockedByToken' => DeployAccess::unlockedBySession($request),
        ]);
    }

    public function unlock(Request $request): RedirectResponse
    {
        $request->validate(['token' => ['required', 'string']]);

        if (! DeployAccess::matches((string) $request->input('token'))) {
            activity('deploy')
                ->withProperties(['ip' => $request->ip(), 'agent' => $request->userAgent()])
                ->log('محاولة فتح شاشة النشر بمفتاحٍ خاطئ');

            throw ValidationException::withMessages([
                'token' => DeployAccess::configured() ? 'المفتاح غير صحيح.' : 'لا مفتاح نشرٍ مضبوطٌ على هذا الخادم.',
            ]);
        }

        DeployAccess::unlock($request);

        activity('deploy')
            ->withProperties(['ip' => $request->ip()])
            ->log('فُتحت شاشة النشر بالمفتاح');

        return to_route('deployment.index');
    }

    public function lock(Request $request): RedirectResponse
    {
        DeployAccess::lock($request);

        return to_route('deployment.index');
    }

    public function run(RunDeploymentRequest $request, StreamDeployAction $deploy): StreamedResponse
    {
        $data = $request->validated();

        $options = $data['options'] ?? [];
        $seeders = $data['seeders'] ?? [];
        $branch = $data['branch'] ?? null;

        DeployPreferences::remember($options, $seeders, $branch);

        return $deploy->handle($this->commandOptions($data, $options, $seeders, $branch), [
            'ip' => $request->ip(),
            'via' => DeployAccess::isSuperAdmin($request) ? 'ui' : 'ui-token',
            'user' => $request->user()?->name,
        ]);
    }

    /**
     * ترجمة اختيارات الشاشة إلى خيارات الأمر. الشاشة تُعلّم ما تريده، والأمر
     * يُنصت لما يُتخطّى، فالنفي يقع هنا في موضعٍ واحد بدل أن يتناثر.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, bool>  $options
     * @param  list<string>  $seeders
     * @return array<string, mixed>
     */
    private function commandOptions(array $data, array $options, array $seeders, ?string $branch): array
    {
        $enabled = fn (string $key): bool => (bool) ($options[$key] ?? true);

        $command = ['--force' => true];

        foreach ([
            'pull' => '--skip-pull',
            'composer' => '--skip-composer',
            'backup' => '--skip-backup',
            'migrate' => '--skip-migrations',
            'maintenance' => '--skip-maintenance',
            'health' => '--skip-health',
        ] as $key => $flag) {
            if (! $enabled($key)) {
                $command[$flag] = true;
            }
        }

        if (! $enabled('rollback')) {
            $command['--no-rollback'] = true;
        }

        if (! $enabled('seed') || $seeders === []) {
            $command['--skip-seed'] = true;
        } else {
            $command['--seeders'] = implode(',', $seeders);

            // التأكيد جرى في التحقّق؛ وهذا ما يفتح للأمر بابه.
            if (DeploySeeders::anyDemo($seeders)) {
                $command['--allow-demo-seeders'] = true;
            }
        }

        if (! empty($data['dryRun'])) {
            $command['--dry-run'] = true;
        }

        if ($branch !== null && $branch !== '') {
            $command['--branch'] = $branch;
        }

        if (! empty($data['assets'])) {
            $command['--assets'] = $data['assets'];
        }

        return $command;
    }

    /**
     * @return array<string, mixed>
     */
    private function environment(): array
    {
        $php = PhpBinary::describe();
        $problem = PhpBinary::misconfigured();

        return [
            'name' => (string) config('app.name'),
            'env' => (string) config('app.env'),
            'url' => (string) config('app.url'),
            'php' => PHP_VERSION,
            // المُفسِّر الذي تُشغَّل به العمليات الفرعية — قد يخالف الذي يخدم
            // الطلب نفسه، وهو موضع الخلل المعتاد على cPanel.
            'phpBinary' => $php['path'],
            'phpBinaryVersion' => $php['version'],
            'phpBinarySource' => $php['source'],
            'phpBinaryOk' => $problem === null && ($php['versionId'] === null || $php['versionId'] >= PhpBinary::MINIMUM),
            'phpBinaryNote' => $problem,
            // ما يقرّر مصير الزارع التجريبي: faker موجودة أصلاً، أو composer
            // قادرٌ على جلبها في هذه النشرة.
            'fakerInstalled' => DeploySeeders::fakerAvailable(),
            'composerAvailable' => ComposerBinary::available(),
            'database' => (string) config('database.default'),
            'branch' => $this->git(['rev-parse', '--abbrev-ref', 'HEAD']),
            'commit' => $this->git(['rev-parse', '--short', 'HEAD']),
            'committedAt' => $this->git(['log', '-1', '--format=%cd', '--date=format:%Y-%m-%d %H:%M']),
        ];
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(array $arguments): ?string
    {
        $binary = Shell::locate('git', ['/usr/bin/git', '/usr/local/bin/git']);

        if ($binary === null) {
            return null;
        }

        try {
            $result = Process::path(base_path())->timeout(15)->run([$binary, ...$arguments]);
        } catch (Throwable) {
            return null;
        }

        return $result->successful() ? trim($result->output()) : null;
    }

    /**
     * @return list<array{id: int, description: string, status: int|null, via: string|null, user: string|null, at: string|null}>
     */
    private function history(): array
    {
        return Activity::query()
            ->where('log_name', 'deploy')
            ->latest()
            ->limit(8)
            ->get()
            ->map(fn (Activity $activity): array => [
                'id' => $activity->id,
                'description' => (string) $activity->description,
                'status' => $activity->properties['status'] ?? null,
                'via' => $activity->properties['via'] ?? null,
                'user' => $activity->properties['user'] ?? null,
                'at' => $activity->created_at?->format('Y-m-d H:i'),
            ])
            ->all();
    }
}
