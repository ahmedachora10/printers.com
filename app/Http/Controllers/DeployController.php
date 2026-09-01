<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تشغيل أمر النشر من الويب — ليَقدر خطُّ GitHub Actions أن يُطلقه بعد بناء
 * الأصول، وليُغني عن سطر الأوامر على استضافةٍ لا تُتيحه دائماً.
 *
 * الباب مغلقٌ ما لم يوجد مفتاحٌ في البيئة، ولا يفتحه إلا مفتاحٌ مطابق. ومخرجات
 * الأمر تُدفَق كما تُكتب، فمن يُطلقه يرى أين وصل بدل أن ينتظر صفحةً بيضاء
 * قد تنقطع قبل أن تعود.
 */
class DeployController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $token = (string) config('deploy.token');

        abort_unless(config('deploy.enabled') && $token !== '', 404);

        $provided = (string) ($request->header('X-Deploy-Token') ?? $request->query('token', ''));

        if (! hash_equals($token, $provided)) {
            activity('deploy')
                ->withProperties(['ip' => $request->ip(), 'agent' => $request->userAgent()])
                ->log('محاولة نشر بمفتاحٍ خاطئ');

            abort(403);
        }

        $options = $this->options($request);

        // قفلٌ واحد للنشر كله: طلبٌ ثانٍ أثناء الأول يُردّ ولا يُصطفّ، فتداخل
        // نشرين على المستودع نفسه يترك الشجرة في حالٍ لا يصفها أحد.
        $lock = Cache::lock('deploy:running', max(60, (int) config('deploy.lock_seconds')));

        abort_if(! $lock->get(), 409, 'هناك نشرٌ قيد التنفيذ الآن.');

        return response()->stream(function () use ($request, $lock, $options): void {
            // الاتصال قد ينقطع من الوسيط أو المتصفح، والنشر لا يحتمل بتراً في
            // منتصفه، فنُكمل ولو انصرف من طلبه.
            @set_time_limit(0);
            ignore_user_abort(true);

            // نفكّ التخزين المؤقت للمخرجات حتى يصل كل سطرٍ فور كتابته، فلا
            // يقطع وسيطٌ الاتصالَ لطول صمته. أما تحت سطر الأوامر — أي في
            // الاختبارات — فالمخزن المؤقت ملك غيرنا، فلا نمسّه.
            if (! app()->runningInConsole()) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }

                ob_implicit_flush(true);
            }

            $output = new StreamOutput(fopen('php://output', 'w'), StreamOutput::VERBOSITY_NORMAL, false);

            try {
                $status = Artisan::call('app:deploy', $options, $output);
            } finally {
                $lock->release();
            }

            activity('deploy')
                ->withProperties(['ip' => $request->ip(), 'options' => $options, 'status' => $status])
                ->log($status === 0 ? 'نشرٌ ناجح من الويب' : 'نشرٌ فاشل من الويب');

            echo PHP_EOL.($status === 0 ? '== اكتمل النشر ==' : '== فشل النشر ==').PHP_EOL;
        }, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(Request $request): array
    {
        $options = ['--force' => true];

        if ($request->boolean('dry')) {
            $options['--dry-run'] = true;
        }

        $branch = trim((string) $request->input('branch', ''));

        if ($branch !== '') {
            abort_unless((bool) preg_match('/^[\w.\/-]+$/', $branch), 422, 'اسم فرعٍ غير صالح.');

            $options['--branch'] = $branch;
        }

        $assets = trim((string) $request->input('assets', ''));

        if ($assets !== '') {
            $options['--assets'] = $this->assets($assets);
        }

        return $options;
    }

    /**
     * رابطٌ للأصول أو ملفٌّ داخل مجلّد التطبيق — لا مسارات مطلقة من الخارج.
     */
    private function assets(string $assets): string
    {
        if (Str::startsWith($assets, ['http://', 'https://'])) {
            return $assets;
        }

        $path = realpath(base_path($assets));

        abort_if($path === false || ! Str::startsWith($path, realpath(base_path()).DIRECTORY_SEPARATOR), 422, 'مسار أصولٍ غير صالح.');

        return $path;
    }
}
