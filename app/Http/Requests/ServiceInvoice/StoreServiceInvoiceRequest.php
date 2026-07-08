<?php

namespace App\Http\Requests\ServiceInvoice;

use App\Enums\InvoiceStatusEnum;
use App\Models\PaymentMethod;
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
            'walkin_tax_number' => ['nullable', 'string', 'digits:15'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
            'redeem_points' => ['nullable', 'integer', 'min:0'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            // A bank-transfer (requires-attachment) method must carry its proof.
            'receipt' => [
                $this->paymentMethodRequiresAttachment() ? 'required' : 'nullable',
                'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120',
            ],
            'status' => ['required', Rule::in($allowedStatuses)],
            'print' => ['nullable', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.branch_service_id' => ['required', 'integer', 'exists:branch_services,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    /**
     * Whether the chosen payment method mandates a receipt upload.
     */
    private function paymentMethodRequiresAttachment(): bool
    {
        $id = $this->input('payment_method_id');

        return $id !== null && (bool) PaymentMethod::find($id)?->requires_attachment;
    }

    public function messages(): array
    {
        return [
            'walkin_tax_number.digits' => 'الرقم الضريبي يجب أن يكون 15 رقماً.',
            'receipt.required' => 'يجب إرفاق إيصال التحويل لطريقة الدفع المحددة.',
            'receipt.mimes' => 'يجب أن يكون الإيصال صورة (jpg, png, webp) أو ملف PDF.',
            'receipt.max' => 'حجم الإيصال يجب ألا يتجاوز 5 ميجابايت.',
            'status.in' => 'لا يمكنك إصدار فاتورة مدفوعة. يتم حفظ الفاتورة كمعلقة ليراجعها المحاسب.',
            'lines.required' => 'يجب إضافة خدمة واحدة على الأقل للفاتورة.',
            'lines.min' => 'يجب إضافة خدمة واحدة على الأقل للفاتورة.',
            'lines.*.branch_service_id.required' => 'يجب اختيار خدمة لكل سطر.',
            'lines.*.branch_service_id.exists' => 'الخدمة المحددة غير موجودة.',
            'lines.*.qty.min' => 'الكمية يجب أن تكون 1 على الأقل.',
        ];
    }
}
