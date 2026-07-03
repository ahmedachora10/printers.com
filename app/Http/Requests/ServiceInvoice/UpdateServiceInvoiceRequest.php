<?php

namespace App\Http\Requests\ServiceInvoice;

use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;

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
            'agent_id' => ['nullable', 'integer', 'exists:users,id'],
            'walkin_name' => ['nullable', 'string', 'max:255'],
            'walkin_phone' => ['nullable', 'string', 'max:30'],
            'coupon_code' => ['nullable', 'string', 'max:100'],
            'redeem_points' => ['nullable', 'integer', 'min:0'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'receipt' => [
                $this->paymentMethodRequiresAttachment() ? 'required' : 'nullable',
                'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120',
            ],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.branch_service_id' => ['required', 'integer', 'exists:branch_services,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'between:0,100'],
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
            'receipt.required' => 'يجب إرفاق إيصال التحويل لطريقة الدفع المحددة.',
            'receipt.mimes' => 'يجب أن يكون الإيصال صورة (jpg, png, webp) أو ملف PDF.',
            'receipt.max' => 'حجم الإيصال يجب ألا يتجاوز 5 ميجابايت.',
            'lines.required' => 'يجب إضافة خدمة واحدة على الأقل للفاتورة.',
            'lines.min' => 'يجب إضافة خدمة واحدة على الأقل للفاتورة.',
            'lines.*.branch_service_id.required' => 'يجب اختيار خدمة لكل سطر.',
            'lines.*.branch_service_id.exists' => 'الخدمة المحددة غير موجودة.',
            'lines.*.qty.min' => 'الكمية يجب أن تكون 1 على الأقل.',
        ];
    }
}
