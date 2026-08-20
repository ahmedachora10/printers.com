<?php

namespace App\Console\Commands;

use App\Actions\Loyalty\ExpireLoyaltyPointsAction;
use Illuminate\Console\Command;

class ExpireLoyaltyPointsCommand extends Command
{
    protected $signature = 'loyalty:expire-points {--branch= : قصر التنفيذ على فرع بعينه}';

    protected $description = 'تصفير نقاط الولاء للعملاء الخاملين وفق مدة كل فرع';

    public function handle(ExpireLoyaltyPointsAction $action): int
    {
        $branchId = $this->option('branch') !== null ? (int) $this->option('branch') : null;

        $expired = $action->handle($branchId);

        $this->info($expired === 0
            ? 'لا توجد أرصدة خاملة انتهت صلاحيتها.'
            : "انتهت صلاحية نقاط {$expired} عميلاً.");

        return self::SUCCESS;
    }
}
