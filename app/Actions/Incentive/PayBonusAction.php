<?php

namespace App\Actions\Incentive;

use App\Enums\IncentivePlanStatusEnum;
use App\Models\BonusPayment;
use App\Models\IncentivePlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayBonusAction
{
    public function __construct(private RecalculateIncentivePlanAction $recalculate) {}

    /**
     * تاسك 214: يصرف شريحةً بعينها متى بلغها المحقَّق — قبل نهاية الشهر وبعد
     * الانتقال إلى أعلى منها — بمكافأتها كاملةً. والخطة تُجمَّد (Paid) حين لا
     * يبقى ما يُصرف.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(IncentivePlan $plan, int $tierNumber, array $data): BonusPayment
    {
        return DB::transaction(function () use ($plan, $tierNumber, $data) {
            $plan = IncentivePlan::query()->lockForUpdate()->findOrFail($plan->id);

            if ($plan->status === IncentivePlanStatusEnum::Paid) {
                throw ValidationException::withMessages([
                    'incentive_plan_id' => 'تم صرف مكافأة هذه الخطة مسبقاً.',
                ]);
            }

            // Settle on the latest sales figures before paying out.
            $this->recalculate->handle($plan);

            $tier = $plan->reachedTiers()[$tierNumber] ?? null;
            if ($tier === null) {
                throw ValidationException::withMessages([
                    'incentive_plan_id' => $plan->isMultiTier() ? 'لم تُبلغ هذه الشريحة بعد.' : 'لم يتم تحقيق هدف هذه الخطة بعد.',
                ]);
            }

            if (in_array($tierNumber, $plan->paidTierNumbers(), true)) {
                throw ValidationException::withMessages([
                    'incentive_plan_id' => 'تم صرف مكافأة هذه الشريحة مسبقاً.',
                ]);
            }

            $payment = BonusPayment::create([
                'incentive_plan_id' => $plan->id,
                'tier_number' => $tierNumber,
                'paid_by' => auth()->id(),
                'amount' => $plan->tierBonus($tier),
                'paid_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            if ($plan->load('bonusPayments')->isFullyPaid()) {
                $plan->update(['status' => IncentivePlanStatusEnum::Paid]);
            }

            return $payment;
        });
    }
}
