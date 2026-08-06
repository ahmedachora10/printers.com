<?php

namespace App\Http\Requests\ProductInvoice;

use App\Enums\InvoiceStatusEnum;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductInvoiceRequest extends FormRequest
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
            // A bank-transfer (requires-attachment) method must carry its proof.
            'receipt' => [
                $this->paymentMethodRequiresAttachment() ? 'required' : 'nullable',
                'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120',
            ],
            'status' => ['required', Rule::in([InvoiceStatusEnum::PAID->value, InvoiceStatusEnum::DUE->value])],
            'print' => ['nullable', 'boolean'],
            // Invoice-level remark for the customer, printed under the lines.
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'lines.*.name' => ['nullable', 'required_without:lines.*.product_id', 'string', 'max:255'],
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
            'receipt.required' => 'يجب إرفاق إيصال التحويل لطريقة الدفع المحددة.',
            'receipt.mimes' => 'يجب أن يكون الإيصال صورة (jpg, png, webp) أو ملف PDF.',
            'receipt.max' => 'حجم الإيصال يجب ألا يتجاوز 5 ميجابايت.',
            'notes.max' => 'ملاحظات الفاتورة يجب ألا تتجاوز 1000 حرف.',
            'lines.required' => 'يجب إضافة منتج واحد على الأقل للفاتورة.',
            'lines.min' => 'يجب إضافة منتج واحد على الأقل للفاتورة.',
            'lines.*.qty.min' => 'الكمية يجب أن تكون 1 على الأقل.',
            'lines.*.product_id.exists' => 'المنتج المحدد غير موجود.',
            'lines.*.name.required_without' => 'اسم السطر اليدوي مطلوب.',
        ];
    }
}
