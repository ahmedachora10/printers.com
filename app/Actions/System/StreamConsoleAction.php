<?php

namespace App\Actions\System;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * دفعُ مخرجات عملٍ طويل إلى المتصفّح كما تُكتب.
 *
 * يشترك فيه بابان: النشر الكامل، والأوامر المفردة. والقفل بينهما واحدٌ عمداً
 * — هجرةٌ تُشغَّل في تبويبٍ بينما نشرةٌ تعمل في آخر تترك الشجرة في حالٍ لا
 * يصفها أحد.
 */
class StreamConsoleAction
{
    public const LOCK = 'deploy:running';

    /**
     * @param  callable(OutputInterface): int  $work  يُرجع رمز الخروج
     * @param  array<string, mixed>  $context  ما يُضاف إلى سجلّ النشاط
     */
    public function handle(
        callable $work,
        array $context = [],
        string $logName = 'deploy',
        string $doneMarker = '== اكتمل النشر ==',
        string $failMarker = '== فشل النشر ==',
        string $busyMessage = 'هناك نشرٌ قيد التنفيذ الآن.',
    ): StreamedResponse {
        $lock = Cache::lock(self::LOCK, max(60, (int) config('deploy.lock_seconds')));

        abort_if(! $lock->get(), 409, $busyMessage);

        return response()->stream(function () use ($lock, $work, $context, $logName, $doneMarker, $failMarker): void {
            // الاتصال قد ينقطع من الوسيط أو المتصفح، والعمل لا يحتمل بتراً في
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
                $status = $work($output);
            } catch (Throwable $e) {
                // العملُ يرمي أحياناً بدل أن يُرجع رمزاً — نسخةٌ احتياطية
                // تتعثّر مثلاً. والرسالة تُكتب في الدفق لا في صفحة خطأ، فقد
                // مضى وقتٌ على إرسال الترويسة.
                $output->writeln('');
                $output->writeln($e->getMessage());
                $status = 1;
            } finally {
                $lock->release();
            }

            activity($logName)
                ->withProperties($context + ['status' => $status])
                ->log(($context['task'] ?? 'نشر').($status === 0 ? ' — نجح' : ' — فشل'));

            echo PHP_EOL.($status === 0 ? $doneMarker : $failMarker).PHP_EOL;
        }, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
