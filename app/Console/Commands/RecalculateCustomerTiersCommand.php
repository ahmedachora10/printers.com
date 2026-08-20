<?php

namespace App\Console\Commands;

use App\Actions\Loyalty\RecalculateCustomerTiersAction;
use App\Enums\CustomerTierEnum;
use Illuminate\Console\Command;

class RecalculateCustomerTiersCommand extends Command
{
    protected $signature = 'loyalty:recalculate-tiers
        {--branch= : قصر التنفيذ على فرع بعينه}
        {--dry-run : اعرض ما سيتغيّر بلا حفظ}';

    protected $description = 'إعادة بناء الإنفاق التراكمي وفئة الولاء لكل عميل من فواتيره المدفوعة';

    public function handle(RecalculateCustomerTiersAction $action): int
    {
        $branchId = $this->option('branch') !== null ? (int) $this->option('branch') : null;
        $dryRun = (bool) $this->option('dry-run');

        $summary = $action->handle($branchId, $dryRun);

        if ($summary['changed'] === 0) {
            $this->info("فُحص {$summary['scanned']} عميلاً — لا شيء يحتاج تصحيحاً.");

            return self::SUCCESS;
        }

        $this->table(
            ['العميل', 'الإنفاق قبل', 'الإنفاق بعد', 'الفئة قبل', 'الفئة بعد'],
            $summary['rows']->map(fn (array $row) => [
                $row['customerName'],
                number_format($row['fromSpend'], 2),
                number_format($row['toSpend'], 2),
                $this->tierLabel($row['fromTier']),
                $this->tierLabel($row['toTier']),
            ])->all(),
        );

        $this->line(sprintf(
            'فُحص %d عميلاً: %d صفّاً تغيّر (%d ترقية، %d تنزيل، %d تصحيح إنفاق).',
            $summary['scanned'],
            $summary['changed'],
            $summary['promoted'],
            $summary['demoted'],
            $summary['spendCorrected'],
        ));

        if ($dryRun) {
            $this->warn('تشغيل تجريبي — لم يُحفظ شيء. أعد التشغيل بلا ‎--dry-run‎ للتنفيذ.');
        } else {
            $this->info('حُفظت التغييرات، وسُجّل كلٌّ منها في سجلّ نشاط العميل.');
        }

        return self::SUCCESS;
    }

    private function tierLabel(CustomerTierEnum $tier): string
    {
        return $tier->label();
    }
}
