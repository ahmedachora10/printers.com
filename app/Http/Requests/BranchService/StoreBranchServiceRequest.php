<?php

namespace App\Http\Requests\BranchService;

use App\Enums\ServicePricingTypeEnum;
use App\Http\Requests\BranchService\Concerns\HandlesNoteExamples;
use App\Http\Requests\BranchService\Concerns\ValidatesSellingPriceBounds;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchServiceRequest extends FormRequest
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
            // خدمة عامة أو مملوكة لهذا الفرع بعينه — لا تُربط خدمةُ فرعٍ آخر
            // بفرعنا مهما كان دور الرابط (تاسك 45).
            'service_template_id' => [
                'required',
                Rule::exists('service_templates', 'id')
                    ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $this->input('branch_id'))),
            ],
            'branch_id' => [
                'required',
                'exists:branches,id',
                Rule::unique('branch_services', 'branch_id')
                    ->where('service_template_id', $this->input('service_template_id')),
            ],
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
            'branch_id.unique' => 'هذا الفرع مرتبط بالفعل بقالب الخدمة هذا.',
            'service_template_id.exists' => 'الخدمة غير متاحة لهذا الفرع.',
            'price_per_sqm.required_if' => 'أدخل سعر وحدة القياس للخدمات المسعّرة بالمتر المربع أو الطولي.',
        ];
    }
}
