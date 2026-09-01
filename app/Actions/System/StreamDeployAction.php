<?php

namespace App\Actions\System;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * تشغيل أمر النشر ودفعُ مخرجاته كما تُكتب. يشترك فيه بابان: مسار المفتاح
 * الذي يُطلقه خطُّ التكامل، وشاشةُ السوبر أدمن داخل التطبيق. الفارق بينهما
 * في من يُؤذن له لا في ما يجري، فجُمع ما يجري هنا مرةً واحدة.
 */
class StreamDeployAction
{
    /**
     * @param  array<string, mixed>  $options  خيارات أمر app:deploy
     * @param  array<string, mixed>  $context  ما يُضاف إلى سجلّ النشاط
     */
    public function handle(array $options, array $context = []): StreamedResponse
    {
        // قفلٌ واحد للنشر كله: طلبٌ ثانٍ أثناء الأول يُردّ ولا يُصطفّ، فتداخل
        // نشرين على المستودع نفسه يترك الشجرة في حالٍ لا يصفها أحد.
        $lock = Cache::lock('deploy:running', max(60, (int) config('deploy.lock_seconds')));

        abort_if(! $lock->get(), 409, 'هناك نشرٌ قيد التنفيذ الآن.');

        return response()->stream(function () use ($lock, $options, $context): void {
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
                ->withProperties($context + ['options' => $options, 'status' => $status])
                ->log($status === 0 ? 'نشرٌ ناجح' : 'نشرٌ فاشل');

            echo PHP_EOL.($status === 0 ? '== اكتمل النشر ==' : '== فشل النشر ==').PHP_EOL;
        }, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
