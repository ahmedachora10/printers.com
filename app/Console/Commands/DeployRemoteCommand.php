<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * إطلاق النشر من جهاز المطوّر — يُنادي مسار ‎/deploy‎ على الخادم بمفتاحه،
 * ويطبع ما يكتبه الأمر هناك سطراً سطراً كأنّه يعمل هنا.
 *
 * لا شيء من العمل يجري محلياً: السحب والبناء والهجرات والنسخة الاحتياطية
 * كلها على الخادم. هذا بابٌ فحسب، فائدته أن يُطلق النشر ويُري صاحبه أين
 * وصل، ويردّ برمز خروجٍ يفهمه خطُّ التكامل بدل صفحةٍ تُقرأ بالعين.
 */
class DeployRemoteCommand extends Command
{
    protected $signature = 'app:deploy-remote
        {--url= : عنوان الموقع (افتراضاً DEPLOY_REMOTE_URL)}
        {--token= : مفتاح النشر (افتراضاً DEPLOY_REMOTE_TOKEN)}
        {--branch= : الفرع الذي يُسحب على الخادم}
        {--assets= : رابط أو مسار build.zip يُمرَّر للخادم}
        {--dry-run : عرض خطوات النشر على الخادم دون تنفيذها}
        {--timeout=1800 : أقصى انتظارٍ للاستجابة بالثواني}
        {--force : تنفيذ دون سؤال تأكيد}';

    protected $description = 'إطلاق النشر على خادم الإنتاج من هنا عبر مسار /deploy';

    public function handle(): int
    {
        $url = rtrim((string) ($this->option('url') ?: config('deploy.remote.url')), '/');
        $token = (string) ($this->option('token') ?: config('deploy.remote.token'));

        if ($url === '') {
            $this->components->error('لا عنوان للخادم — اضبط DEPLOY_REMOTE_URL أو مرّر ‎--url=‎.');

            return self::FAILURE;
        }

        if ($token === '') {
            $this->components->error('لا مفتاح للنشر — اضبط DEPLOY_REMOTE_TOKEN أو مرّر ‎--token=‎.');

            return self::FAILURE;
        }

        $target = $url.'/deploy';
        $dry = (bool) $this->option('dry-run');

        $this->newLine();
        $this->components->twoColumnDetail('الخادم', $target);
        $this->components->twoColumnDetail('الفرع', (string) ($this->option('branch') ?: 'الفرع الحالي على الخادم'));

        if ($dry) {
            $this->components->twoColumnDetail('الوضع', '<fg=yellow>عرضٌ فقط</>');
        }

        $this->newLine();

        // بيئة هذا الجهاز محليةٌ دائماً، فسؤال التأكيد المعتاد لا ينفع هنا:
        // الوجهة هي الإنتاج ولو كان المُطلِق جالساً في بيته.
        if (! $dry && ! $this->option('force') && ! $this->confirm('سيجري النشر على '.$url.'. أتُتابع؟', false)) {
            $this->components->warn('أُلغي النشر.');

            return self::FAILURE;
        }

        try {
            $response = Http::withHeaders(['X-Deploy-Token' => $token])
                ->withOptions(['stream' => true])
                ->timeout(max(60, (int) $this->option('timeout')))
                ->connectTimeout(30)
                ->post($target.'?'.http_build_query($this->query()));
        } catch (Throwable $e) {
            $this->components->error('تعذّر الوصول إلى الخادم: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($response->status() !== 200) {
            $this->components->error($this->explain($response->status()));

            return self::FAILURE;
        }

        return $this->stream($response->toPsrResponse()->getBody());
    }

    /**
     * @return array<string, string>
     */
    private function query(): array
    {
        $query = [];

        if ($this->option('dry-run')) {
            $query['dry'] = '1';
        }

        foreach (['branch', 'assets'] as $option) {
            $value = trim((string) ($this->option($option) ?? ''));

            if ($value !== '') {
                $query[$option] = $value;
            }
        }

        return $query;
    }

    /**
     * المخرجات تصل مُقطَّعة، فنكتبها فور وصولها ولا ننتظر آخرها. ورمز الخروج
     * يُقرأ من السطر الختامي الذي يكتبه الخادم، إذ الترويسة سبقت النشر كلّه
     * فلا تحمل خبر نجاحه.
     */
    private function stream(StreamInterface $body): int
    {
        $tail = '';

        try {
            while (! $body->eof()) {
                $chunk = $body->read(8192);

                if ($chunk === '') {
                    continue;
                }

                $this->output->write($chunk, false, OutputInterface::OUTPUT_RAW);

                // يكفي ذيلٌ قصير للعلامة الختامية، فلا نحتفظ بالمخرجات كلها.
                $tail = substr($tail.$chunk, -512);
            }
        } catch (Throwable $e) {
            $this->newLine();
            $this->components->error('انقطع الاتصال قبل نهاية النشر: '.$e->getMessage());
            $this->components->warn('النشر يمضي على الخادم رغم الانقطاع — راجع سجلّه هناك.');

            return self::FAILURE;
        }

        $this->newLine();

        if (str_contains($tail, '== اكتمل النشر ==')) {
            $this->components->info('اكتمل النشر على '.rtrim((string) ($this->option('url') ?: config('deploy.remote.url')), '/').'.');

            return self::SUCCESS;
        }

        if (str_contains($tail, '== فشل النشر ==')) {
            $this->components->error('فشل النشر على الخادم — الرسالة أعلاه.');

            return self::FAILURE;
        }

        $this->components->warn('انتهت المخرجات دون علامةٍ ختامية — تحقّق من حال الموقع بنفسك.');

        return self::FAILURE;
    }

    private function explain(int $status): string
    {
        return match ($status) {
            403 => 'مفتاح النشر مرفوض (403) — راجع DEPLOY_TOKEN على الخادم.',
            404 => 'المسار مغلق (404) — DEPLOY_TOKEN فارغ أو DEPLOY_ENABLED=false على الخادم.',
            409 => 'هناك نشرٌ قيد التنفيذ الآن على الخادم (409).',
            422 => 'رفض الخادم أحد المعطيات (422) — راجع ‎--branch‎ و‎--assets‎.',
            429 => 'تجاوزت حدّ المحاولات (429) — انتظر دقيقة.',
            default => "ردّ الخادم برمزٍ غير متوقّع: {$status}.",
        };
    }
}
