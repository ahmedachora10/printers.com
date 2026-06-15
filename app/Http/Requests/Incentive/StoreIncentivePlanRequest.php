<?php

namespace App\Http\Requests\Incentive;

use App\Enums\IncentiveBonusTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncentivePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required', 'integer', 'exists:users,id',
                Rule::unique('incentive_plans')->where(
                    fn ($q) => $q->where('period_month', $this->input('period_month'))
                        ->where('period_year', $this->input('period_year'))
                ),
            ],
            'period_month' => ['required', 'integer', 'between:1,12'],
            'period_year' => ['required', 'integer', 'between:2020,2100'],
            'target_amount' => ['required', 'numeric', 'min:0.01'],
            'bonus_type' => ['required', Rule::enum(IncentiveBonusTypeEnum::class)],
            'bonus_value' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'user_id.unique' => 'يوجد بالفعل خطة حوافز لهذا الموظف في نفس الشهر.',
        ];
    }
}
