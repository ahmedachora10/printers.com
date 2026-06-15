<?php

namespace App\Actions\Incentive;

use App\Models\IncentivePlan;
use Illuminate\Support\Facades\DB;

class UpdateIncentivePlanAction
{
    public function __construct(private RecalculateIncentivePlanAction $recalculate) {}

    /** @param array<string, mixed> $data */
    public function handle(IncentivePlan $plan, array $data): IncentivePlan
    {
        return DB::transaction(function () use ($plan, $data) {
            $plan->update($data);

            // Target/period may have changed — re-derive achieved sales & status.
            return $this->recalculate->handle($plan);
        });
    }
}
