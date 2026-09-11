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

    /** @param array<string, mixed> $data */
    public function handle(IncentivePlan $plan, array $data): BonusPayment
    {
        return DB::transaction(function () use ($plan, $data) {
            $plan = IncentivePlan::query()->lockForUpdate()->findOrFail($plan->id);

            if ($plan->status === IncentivePlanStatusEnum::Paid) {
                throw ValidationException::withMessages([
                    'incentive_plan_id' => 'تم صرف مكافأة هذه الخطة مسبقاً.',
                ]);
            }

            // تاسك 105: الصرف يجمّد الخطة، فصرفُ خطةٍ ذات شرائح منتصف الشهر كان
            // سيُضيع على الموظف ما فوق شريحته الحالية. ذات الشريحة الواحدة على حالها.
            if ($plan->isMultiTier() && ! $plan->periodEnded()) {
                throw ValidationException::withMessages([
                    'incentive_plan_id' => 'خطة ذات شرائح تُصرف بعد نهاية الشهر.',
                ]);
            }

            // Settle on the latest sales figures before paying out.
            $this->recalculate->handle($plan);

            if (! $plan->isTargetMet()) {
                throw ValidationException::withMessages([
                    'incentive_plan_id' => 'لم يتم تحقيق هدف هذه الخطة بعد.',
                ]);
            }

            $payment = BonusPayment::create([
                'incentive_plan_id' => $plan->id,
                'paid_by' => auth()->id(),
                'amount' => $plan->bonusAmount(),
                'paid_at' => now(),
                'notes' => $data['notes'] ?? null,
            ]);

            $plan->update(['status' => IncentivePlanStatusEnum::Paid]);

            return $payment;
        });
    }
}
