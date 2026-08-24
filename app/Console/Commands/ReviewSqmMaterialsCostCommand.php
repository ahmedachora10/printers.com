<?php

namespace App\Console\Commands;

use App\Enums\ServicePricingTypeEnum;
use App\Models\BranchService;
use Illuminate\Console\Command;

/**
 * تاسك 63: قائمة الخدمات التي تغيّر معنى رقمها.
 *
 * قبل التاسك 63 كان `branch_services.materials_cost` يُضرب في **عدد القطع**
 * دائماً، فأُدخلت قيمه على أنها تكلفةُ قطعة. وبعده صار يُضرب في الوحدات
 * المُفوترة، فقيمةُ خدمةٍ مسعّرة بالمتر المربع تُقرأ الآن تكلفةً **للمتر**.
 *
 * لا هجرة تصحّح ذلك: لا أحد يعرف التكلفة الصحيحة للمتر إلا صاحب العمل، وأي
 * قسمة آلية على «مساحة متوسطة» تُنتج رقماً مخترَعاً يبدو صحيحاً. فيُطبع الجدول
 * هنا ليُراجَع يدوياً على الشاشة قبل النشر.
 */
class ReviewSqmMaterialsCostCommand extends Command
{
    protected $signature = 'services:review-sqm-materials';

    protected $description = 'يسرد خدمات المتر المربع التي لها تكلفة خامات — لمراجعتها بعد أن صار الرقم للمتر لا للقطعة (تاسك 63)';

    public function handle(): int
    {
        $services = BranchService::query()
            ->with(['serviceTemplate', 'branch'])
            ->where('pricing_type', ServicePricingTypeEnum::Sqm)
            ->where('has_materials', true)
            ->where('materials_cost', '>', 0)
            ->get();

        if ($services->isEmpty()) {
            $this->info('لا خدمة مسعّرة بالمتر المربع تحمل تكلفة خامات — لا شيء يُراجَع.');

            return self::SUCCESS;
        }

        $this->warn("{$services->count()} خدمة تغيّر معنى تكلفة خاماتها من «للقطعة» إلى «للمتر المربع»:");
        $this->newLine();

        $this->table(
            ['الفرع', 'الخدمة', 'تكلفة الخامات', 'سعر المتر'],
            $services->map(fn (BranchService $service) => [
                $service->branch?->name ?? '—',
                $service->serviceTemplate?->name ?? '—',
                number_format((float) $service->materials_cost, 2),
                number_format((float) $service->price_per_sqm, 2),
            ])->all(),
        );

        $this->newLine();
        $this->line('راجِع كل صفّ مع صاحب العمل: الرقم يُضرب الآن في مساحة السطر، فخامة 10 ر.س');
        $this->line('على مقاس 100×70 سم تُحمَّل 7 ر.س بدل 10. التصحيح من شاشة الخدمة نفسها.');

        return self::SUCCESS;
    }
}
