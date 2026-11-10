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
     * تاسك 230: يصرف **أعلى شريحة مبلوغة** متى بلغها المحقَّق (قبل نهاية الشهر
     * أيضاً، تاسك 214) بالفرق بين مكافأتها وما صُرف للخطة. الشرائح الأدنى غير
     * المصروفة مشمولةٌ بها. والخطة تُجمَّد (Paid) حين لا يبقى ما يُصرف.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(IncentivePlan $plan, array $data): BonusPayment
    {
        return DB::transaction(function () use ($plan, $data) {
            $plan = IncentivePlan::query()->lockForUpdate()->findOrFail($plan->id);

            if ($plan->status === IncentivePlanStatusEnum::Paid) {
                throw ValidationException::withMessages([
                    'incentive_plan_id' => 'تم صرف مكافأة هذه الخطة مسبقاً.',
                ]);
            }

            // Settle on the latest sales figures before paying out.
            $this->recalculate->handle($plan);

            $tier = $plan->reachedTier();
            if ($tier === null) {
                throw ValidationException::withMessages([
                    'incentive_plan_id' => 'لم يتم تحقيق هدف هذه الخطة بعد.',
                ]);
            }

            $amount = $plan->payableNow();
            if (in_array($tier['number'], $plan->paidTierNumbers(), true) || $amount <= 0) {
                throw ValidationException::withMessages([
                    'incentive_plan_id' => 'تم صرف مكافأة هذه الشريحة مسبقاً.',
                ]);
            }

            $payment = BonusPayment::create([
                'incentive_plan_id' => $plan->id,
                'tier_number' => $tier['number'],
                'paid_by' => auth()->id(),
                'amount' => $amount,
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
