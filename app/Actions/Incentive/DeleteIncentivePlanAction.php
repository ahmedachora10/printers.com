<?php

namespace App\Actions\Incentive;

use App\Models\IncentivePlan;
use Illuminate\Support\Facades\DB;

class DeleteIncentivePlanAction
{
    public function handle(IncentivePlan $plan): void
    {
        DB::transaction(fn () => $plan->delete());
    }
}
