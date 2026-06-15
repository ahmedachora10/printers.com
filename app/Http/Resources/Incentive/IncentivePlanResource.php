<?php

namespace App\Http\Resources\Incentive;

use App\Models\IncentivePlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin IncentivePlan
 */
class IncentivePlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $target = (float) $this->target_amount;
        $achieved = (float) $this->achieved_amount;
        $payment = $this->whenLoaded('bonusPayments', fn () => $this->bonusPayments->first());

        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'userName' => $this->user?->name,
            'branchId' => $this->branch_id,
            'branchName' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'periodMonth' => $this->period_month,
            'periodYear' => $this->period_year,
            'periodLabel' => sprintf('%02d/%d', $this->period_month, $this->period_year),
            'targetAmount' => $target,
            'bonusType' => $this->bonus_type->value,
            'bonusTypeLabel' => $this->bonus_type->label(),
            'bonusValue' => (float) $this->bonus_value,
            'achievedAmount' => $achieved,
            'progressPct' => $target > 0 ? round($achieved / $target * 100, 1) : 0.0,
            'bonusAmount' => $this->bonusAmount(),
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'isTargetMet' => $this->isTargetMet(),
            'notes' => $this->notes,
            'paidAmount' => $payment ? (float) $payment->amount : null,
            'paidAt' => $payment?->paid_at?->format('d/m/Y H:i'),
            'paidBy' => $payment?->paidBy?->name,
        ];
    }
}
