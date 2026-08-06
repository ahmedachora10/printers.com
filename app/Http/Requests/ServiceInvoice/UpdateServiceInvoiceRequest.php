<?php

namespace App\Http\Requests\ServiceInvoice;

use App\Enums\LineAgentCommissionTypeEnum;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an employee's in-place edit of their own DUE service invoice. The
 * status is never submitted — an edited invoice always stays DUE until an
 * accountant approves it — so the rules mirror the store request minus status.
 */
class UpdateServiceInvoiceRequest extends FormRequest
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
            'agent_ids' => ['nullable', 'array'],
            'agent_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'walkin_name' => ['nullable', 'string', 'max:255'],
            'walkin_phone' => ['nullable', 'string', 'max:30'],
            'walkin_tax_number' => ['nullable', 'string', 'digits:15'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
            'redeem_points' => ['nullable', 'integer', 'min:0'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'receipt' => [
                $this->paymentMethodRequiresAttachment() ? 'required' : 'nullable',
                'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120',
            ],
            // Invoice-level remark for the customer, printed under the lines.
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.branch_service_id' => ['required', 'integer', 'exists:branch_services,id'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.width_cm' => ['nullable', 'numeric', 'min:1', 'max:99999'],
            'lines.*.height_cm' => ['nullable', 'numeric', 'min:1', 'max:99999'],
            'lines.*.agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'lines.*.agent_commission_type' => ['nullable', 'required_with:lines.*.agent_id', Rule::enum(LineAgentCommissionTypeEnum::class)],
            'lines.*.agent_commission_value' => ['nullable', 'required_with:lines.*.agent_id', 'numeric', 'min:0'],
        ];
    }

    /**
     * Whether the chosen payment method mandates a receipt upload. On edit the
     * proof is only demanded when none is already attached, so a re-edit that
     * keeps the same bank-transfer method need not re-upload it.
     */
    private function paymentMethodRequiresAttachment(): bool
    {
        $id = $this->input('payment_method_id');
        $requires = $id !== null && (bool) PaymentMethod::find($id)?->requires_attachment;

        return $requires && ! $this->route('invoice')?->hasReceipt();
    }

    public function messages(): array
    {
        return [
            'walkin_tax_number.digits' => 'الرقم الضريبي يجب أن يكون 15 رقماً.',
            'receipt.required' => 'يجب إرفاق إيصال التحويل لطريقة الدفع المحددة.',
            'receipt.mimes' => 'يجب أن يكون الإيصال صورة (jpg, png, webp) أو ملف PDF.',
            'receipt.max' => 'حجم الإيصال يجب ألا يتجاوز 5 ميجابايت.',
            'notes.max' => 'ملاحظات الفاتورة يجب ألا تتجاوز 1000 حرف.',
            'lines.required' => 'يجب إضافة خدمة واحدة على الأقل للفاتورة.',
            'lines.min' => 'يجب إضافة خدمة واحدة على الأقل للفاتورة.',
            'lines.*.branch_service_id.required' => 'يجب اختيار خدمة لكل سطر.',
            'lines.*.branch_service_id.exists' => 'الخدمة المحددة غير موجودة.',
            'lines.*.qty.min' => 'الكمية يجب أن تكون 1 على الأقل.',
            'lines.*.notes.max' => 'تفاصيل الخدمة يجب ألا تتجاوز 500 حرف.',
            'lines.*.width_cm.min' => 'العرض يجب أن يكون 1 سم على الأقل.',
            'lines.*.height_cm.min' => 'الطول يجب أن يكون 1 سم على الأقل.',
            'lines.*.agent_commission_type.required_with' => 'حدد نوع عمولة صاحب العمولة.',
            'lines.*.agent_commission_value.required_with' => 'أدخل قيمة عمولة صاحب العمولة.',
        ];
    }
}
