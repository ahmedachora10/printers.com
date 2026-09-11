<?php

namespace App\Http\Requests\Incentive;

use Illuminate\Validation\Rule;

/** قواعد الإنشاء نفسها، والتفرّد يتجاهل الخطة المعدَّلة. */
class UpdateIncentivePlanRequest extends StoreIncentivePlanRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'user_id' => [
                'required', 'integer', 'exists:users,id',
                Rule::unique('incentive_plans')
                    ->ignore($this->route('incentive_plan'))
                    ->where(
                        fn ($q) => $q->where('period_month', $this->input('period_month'))
                            ->where('period_year', $this->input('period_year'))
                    ),
            ],
        ];
    }
}
