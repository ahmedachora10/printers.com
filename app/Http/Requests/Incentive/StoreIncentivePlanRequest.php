<?php

namespace App\Http\Requests\Incentive;

use App\Enums\IncentiveBonusTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'bonus_type' => ['required', Rule::enum(IncentiveBonusTypeEnum::class)],
            // تاسك 105: الهدف والمكافأة صارا شرائح؛ أدناها يُنسخ إلى target_amount.
            'tiers' => ['required', 'array', 'min:1', 'max:10'],
            'tiers.*.threshold' => ['required', 'numeric', 'min:0.01'],
            'tiers.*.value' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * العتبات بلا تكرار، والمكافأة تصعد مع العتبة — شريحةٌ أعلى بمكافأةٍ لا تزيد
     * لا معنى لها. الترتيب المُدخل لا يهمّ؛ النموذج يرتّب بالعتبة.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $rising = collect($this->input('tiers'))
                ->sortBy(fn ($t) => (float) $t['threshold'])
                ->sliding(2)
                ->every(fn ($pair) => (float) $pair->last()['threshold'] > (float) $pair->first()['threshold']
                    && (float) $pair->last()['value'] > (float) $pair->first()['value']);

            if (! $rising) {
                $validator->errors()->add('tiers', 'لا تتكرّر عتبة، وتزيد المكافأة مع كل شريحة أعلى.');
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'user_id.unique' => 'يوجد بالفعل خطة حوافز لهذا الموظف في نفس الشهر.',
            'tiers.*.threshold.required' => 'أدخل عتبة المبيعات لكل شريحة.',
            'tiers.*.threshold.min' => 'عتبة الشريحة يجب أن تكون أكبر من صفر.',
            'tiers.*.value.required' => 'أدخل قيمة المكافأة لكل شريحة.',
        ];
    }
}
