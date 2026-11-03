<?php

namespace App\Http\Resources\Incentive;

use App\Enums\IncentivePlanStatusEnum;
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
        // تاسك 214: صفٌّ لكل شريحة مصروفة؛ الأحدث أولاً من المتحكّم.
        $payments = $this->relationLoaded('bonusPayments') ? $this->bonusPayments : collect();
        $payment = $payments->first();
        $reachedTiers = $this->reachedTiers();
        $reached = $this->reachedTier();
        // الشرائح مرتّبة، فالتالية هي التي بعد المبلوغة.
        $nextNumber = ($reached['number'] ?? 0) + 1;
        $next = $this->tiers[$nextNumber - 1] ?? null;

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
            'isTargetMet' => $reached !== null,
            // تاسك 105: الشرائح بمكافأة كلٍّ منها، والمبلوغة، والتالية وما بقي إليها.
            'tiers' => array_map(function (array $tier, int $i) use ($payments, $reachedTiers) {
                $paid = $payments->firstWhere('tier_number', $i + 1);

                return [
                    'threshold' => (float) $tier['threshold'],
                    'value' => (float) $tier['value'],
                    'bonus' => $this->tierBonus($tier),
                    'reached' => isset($reachedTiers[$i + 1]),
                    'paidAt' => $paid?->paid_at?->format('d/m/Y H:i'),
                    'paidBy' => $paid?->paidBy?->name,
                    'canPay' => isset($reachedTiers[$i + 1]) && $paid === null && $this->status !== IncentivePlanStatusEnum::Paid,
                ];
            }, $this->tiers, array_keys($this->tiers)),
            'reachedTier' => $reached['number'] ?? null,
            'nextTier' => $next ? ['number' => $nextNumber, 'threshold' => (float) $next['threshold'], 'remaining' => round((float) $next['threshold'] - $achieved, 2)] : null,
            'notes' => $this->notes,
            'paidAmount' => $payment ? round((float) $payments->sum('amount'), 2) : null,
            'paidAt' => $payment?->paid_at?->format('d/m/Y H:i'),
            'paidBy' => $payment?->paidBy?->name,
        ];
    }
}
