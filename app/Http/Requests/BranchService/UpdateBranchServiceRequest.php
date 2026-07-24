<?php

namespace App\Http\Requests\BranchService;

use App\Enums\ServicePricingTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'base_commission_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'max_discount_pct' => ['required', 'numeric', 'min:0', 'max:100'],
            'pricing_type' => ['nullable', Rule::enum(ServicePricingTypeEnum::class)],
            'price_per_sqm' => ['nullable', 'required_if:pricing_type,sqm', 'numeric', 'min:0'],
            'agent_commission_per_sqm' => ['nullable', 'numeric', 'min:0'],
            'is_tahazir' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'price_per_sqm.required_if' => 'أدخل سعر المتر المربع للخدمات المسعّرة بالمتر المربع.',
        ];
    }
}
