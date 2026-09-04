<?php

namespace App\Http\Requests\BranchService;

use App\Enums\ServicePricingTypeEnum;
use App\Http\Requests\BranchService\Concerns\HandlesNoteExamples;
use App\Http\Requests\BranchService\Concerns\ValidatesSellingPriceBounds;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchServiceRequest extends FormRequest
{
    use HandlesNoteExamples;
    use ValidatesSellingPriceBounds;

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
            // سقف سعر البيع وأرضيته (اختياريان): فارغٌ = مفتوح من تلك الجهة.
            // معناهما يتبع نوع التسعير — سعر الوحدة لخدمة بالوحدة، وسعر المتر
            // لخدمة بالمتر المربع.
            'max_selling_price' => ['nullable', 'numeric', 'min:0'],
            'min_selling_price' => ['nullable', 'numeric', 'min:0', ...$this->sellingPriceFloorRules()],
            'pricing_type' => ['nullable', Rule::enum(ServicePricingTypeEnum::class)],
            // تاسك 80: `price_per_sqm` يُقرأ «سعر وحدة القياس» — سعر المتر المربع
            // للمربع وسعر المتر الطولي للطولي، فيلزم في التسعيرين معاً.
            'price_per_sqm' => ['nullable', 'required_if:pricing_type,sqm', 'required_if:pricing_type,linear', 'numeric', 'min:0'],
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
            ...$this->sellingPriceBoundMessages(),
            'price_per_sqm.required_if' => 'أدخل سعر وحدة القياس للخدمات المسعّرة بالمتر المربع أو الطولي.',
        ];
    }
}
