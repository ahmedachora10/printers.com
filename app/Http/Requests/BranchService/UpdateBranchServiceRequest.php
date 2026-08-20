<?php

namespace App\Http\Requests\BranchService;

use App\Enums\ServicePricingTypeEnum;
use App\Http\Requests\BranchService\Concerns\HandlesNoteExamples;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchServiceRequest extends FormRequest
{
    use HandlesNoteExamples;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNoteExamples();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->noteExampleRules(),
            'base_commission_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_discount_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            // سقف سعر البيع (اختياري): فارغ = السعر مفتوح للموظف. معناه يتبع نوع
            // التسعير — سعر الوحدة لخدمة بالوحدة، وسعر المتر لخدمة بالمتر المربع.
            'max_selling_price' => ['nullable', 'numeric', 'min:0'],
            'pricing_type' => ['nullable', Rule::enum(ServicePricingTypeEnum::class)],
            'price_per_sqm' => ['nullable', 'required_if:pricing_type,sqm', 'numeric', 'min:0'],
            'agent_commission_per_sqm' => ['nullable', 'numeric', 'min:0'],
            'is_tahazir' => ['boolean'],
            // الخامات: مفتاح + تكلفة افتراضية للوحدة تُعبَّأ في نقطة البيع. صفر
            // مقبول — يعني «لها خامات لكن المبلغ يُدخَل مع كل فاتورة».
            'has_materials' => ['boolean'],
            'materials_cost' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->noteExampleMessages(),
            'price_per_sqm.required_if' => 'أدخل سعر المتر المربع للخدمات المسعّرة بالمتر المربع.',
        ];
    }
}
