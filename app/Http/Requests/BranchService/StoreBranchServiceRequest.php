<?php

namespace App\Http\Requests\BranchService;

use App\Enums\ServicePricingTypeEnum;
use App\Http\Requests\BranchService\Concerns\HandlesNoteExamples;
use App\Http\Requests\BranchService\Concerns\ValidatesSellingPriceBounds;
use App\Http\Requests\Concerns\ValidatesPriceTiers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBranchServiceRequest extends FormRequest
{
    use HandlesNoteExamples;
    use ValidatesPriceTiers;
    use ValidatesSellingPriceBounds;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeNoteExamples();
        $this->normalizePriceTiers();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->noteExampleRules(),
            // تاسك 241: شرائح السعر حسب الكمية.
            ...$this->priceTierRules(),
            // خدمة مملوكة لهذا الفرع بعينه — لا تُربط خدمةُ فرعٍ آخر بفرعنا (تاسك 45).
            // تاسك 236: والعامة يربطها السوبر أدمن وحده؛ مدير الفرع يحصل عليها بالاستيراد.
            'service_template_id' => [
                'required',
                Rule::exists('service_templates', 'id')
                    ->where(fn ($q) => $this->user()?->roleName->isSuperAdmin()
                        ? $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $this->input('branch_id')))
                        : $q->where('branch_id', $this->input('branch_id'))),
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
            // الخامات: مفتاح + تكلفة افتراضية للوحدة تُعبَّأ في نقطة البيع. صفر
            // مقبول — يعني «لها خامات لكن المبلغ يُدخَل مع كل فاتورة».
            'has_materials' => ['boolean'],
            'materials_cost' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [fn (Validator $v) => $this->priceTierCheck($v, $this->float('min_selling_price'), $this->float('max_selling_price'))];
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
