<?php

namespace App\Http\Requests\ServiceInvoice;

use App\Enums\InvoiceStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'walkin_name' => ['nullable', 'string', 'max:255'],
            'walkin_phone' => ['nullable', 'string', 'max:30'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
            'redeem_points' => ['nullable', 'integer', 'min:0'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'status' => ['required', Rule::in([InvoiceStatusEnum::PAID->value, InvoiceStatusEnum::DUE->value])],
            'print' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.branch_service_id' => ['required', 'integer', 'exists:branch_services,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'يجب إضافة خدمة واحدة على الأقل للفاتورة.',
            'lines.min' => 'يجب إضافة خدمة واحدة على الأقل للفاتورة.',
            'lines.*.branch_service_id.required' => 'يجب اختيار خدمة لكل سطر.',
            'lines.*.branch_service_id.exists' => 'الخدمة المحددة غير موجودة.',
            'lines.*.qty.min' => 'الكمية يجب أن تكون 1 على الأقل.',
        ];
    }
}
