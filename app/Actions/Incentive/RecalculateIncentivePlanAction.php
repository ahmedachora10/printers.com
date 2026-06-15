<?php

namespace App\Actions\Incentive;

use App\Enums\IncentivePlanStatusEnum;
use App\Enums\InvoiceStatusEnum;
use App\Models\IncentivePlan;
use App\Models\ServiceInvoice;
use Carbon\CarbonImmutable;

class RecalculateIncentivePlanAction
{
    /**
     * Refresh a plan's achieved sales and derive its status. A paid plan is
     * frozen — its payout is already recorded and must not be re-evaluated.
     */
    public function handle(IncentivePlan $plan): IncentivePlan
    {
        if ($plan->status === IncentivePlanStatusEnum::Paid) {
            return $plan;
        }

        $achieved = $this->achievedSales($plan);

        $plan->achieved_amount = $achieved;
        $plan->status = $this->deriveStatus($plan, $achieved);
        $plan->save();

        return $plan;
    }

    /**
     * Sum of the employee's own non-cancelled service sales within the plan
     * month. Mirrors how commissions recognise sales at invoice time (not at
     * collection), so due invoices count toward the target.
     */
    public function achievedSales(IncentivePlan $plan): float
    {
        $start = CarbonImmutable::create($plan->period_year, $plan->period_month, 1)->startOfMonth();
        $end = $start->endOfMonth();

        return (float) ServiceInvoice::query()
            ->where('user_id', $plan->user_id)
            ->where('branch_id', $plan->branch_id)
            ->where('status', '!=', InvoiceStatusEnum::CANCELLED->value)
            ->whereBetween('created_at', [$start, $end])
            ->sum('total_amount');
    }

    private function deriveStatus(IncentivePlan $plan, float $achieved): IncentivePlanStatusEnum
    {
        if ($achieved >= (float) $plan->target_amount) {
            return IncentivePlanStatusEnum::Achieved;
        }

        // Target not met. Once the month is over the plan can no longer recover.
        $periodEnd = CarbonImmutable::create($plan->period_year, $plan->period_month, 1)->endOfMonth();

        return $periodEnd->isPast()
            ? IncentivePlanStatusEnum::Missed
            : IncentivePlanStatusEnum::Active;
    }
}
