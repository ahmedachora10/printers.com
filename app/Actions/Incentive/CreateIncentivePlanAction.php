<?php

namespace App\Actions\Incentive;

use App\Models\IncentivePlan;
use Illuminate\Support\Facades\DB;

class CreateIncentivePlanAction
{
    public function __construct(private RecalculateIncentivePlanAction $recalculate) {}

    /** @param array<string, mixed> $data */
    public function handle(array $data): IncentivePlan
    {
        return DB::transaction(function () use ($data) {
            $plan = IncentivePlan::create($data);

            // Seed achieved sales/status from any invoices already on the books.
            return $this->recalculate->handle($plan);
        });
    }
}
