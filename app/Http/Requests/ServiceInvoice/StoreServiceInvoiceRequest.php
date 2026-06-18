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
        // Employees may only raise DUE (معلق) invoices — an accountant or
        // branch admin reviews them before they are marked paid. Higher roles
        // can still settle invoices as PAID directly from the POS.
        $allowedStatuses = $this->user()?->roleName?->isEmployee()
            ? [InvoiceStatusEnum::DUE->value]
            : [InvoiceStatusEnum::PAID->value, InvoiceStatusEnum::DUE->value];

        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'walkin_name' => ['nullable', 'string', 'max:255'],
            'walkin_phone' => ['nullable', 'string', 'max:30'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
            'redeem_points' => ['nullable', 'integer', 'min:0'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'status' => ['required', Rule::in($allowedStatuses)],
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
            'status.in' => 'لا يمكنك إصدار فاتورة مدفوعة. يتم حفظ الفاتورة كمعلقة ليراجعها المحاسب.',
            'lines.required' => 'يجب إضافة خدمة واحدة على الأقل للفاتورة.',
            'lines.min' => 'يجب إضافة خدمة واحدة على الأقل للفاتورة.',
            'lines.*.branch_service_id.required' => 'يجب اختيار خدمة لكل سطر.',
            'lines.*.branch_service_id.exists' => 'الخدمة المحددة غير موجودة.',
            'lines.*.qty.min' => 'الكمية يجب أن تكون 1 على الأقل.',
        ];
    }
}
