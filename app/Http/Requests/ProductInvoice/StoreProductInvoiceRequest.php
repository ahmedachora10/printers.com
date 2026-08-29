<?php

namespace App\Http\Requests\ProductInvoice;

use App\Enums\InvoiceStatusEnum;
use App\Models\Branch;
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
            // طريقة الدفع إلزامية على كل فاتورة، مدفوعةً كانت أو آجلة: فاتورةٌ
            // بلا طريقة تسقط من تفصيل طرق الدفع في التقارير، ويتعثّر اعتمادها
            // لاحقاً عند المحاسب. ولا تُقبل إلا طريقةٌ يراها فرع الفاتورة.
            'payment_method_id' => ['required', 'integer', Rule::in($this->enabledPaymentMethodIds())],
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
            // كمية عشرية منذ تاسك 51 — المنتج المسعّر بالمتر يُباع بكسور المتر.
            // وكمية سطر ذلك المنتج يشتقّها الخادم من المقاس، فما يصل هنا لا يُعتمد له.
            'lines.*.qty' => ['required', 'numeric', 'min:0.01'],
            'lines.*.width_cm' => ['nullable', 'numeric', 'min:1', 'max:99999'],
            'lines.*.height_cm' => ['nullable', 'numeric', 'min:1', 'max:99999'],
            'lines.*.pieces' => ['nullable', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    /**
     * The payment methods enabled for the branch this invoice is raised in —
     * the very list the POS screen offered the cashier.
     *
     * @return array<int, int>
     */
    private function enabledPaymentMethodIds(): array
    {
        $branch = Branch::find($this->user()?->branchId);

        return $branch ? $branch->enabledPaymentMethods()->pluck('id')->all() : [];
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
            'payment_method_id.required' => 'طريقة الدفع مطلوبة — اخترها قبل حفظ الفاتورة.',
            'payment_method_id.in' => 'طريقة الدفع غير متاحة لهذا الفرع.',
            'receipt.required' => 'يجب إرفاق إيصال التحويل لطريقة الدفع المحددة.',
            'receipt.mimes' => 'يجب أن يكون الإيصال صورة (jpg, png, webp) أو ملف PDF.',
            'receipt.max' => 'حجم الإيصال يجب ألا يتجاوز 5 ميجابايت.',
            'notes.max' => 'ملاحظات الفاتورة يجب ألا تتجاوز 1000 حرف.',
            'lines.required' => 'يجب إضافة منتج واحد على الأقل للفاتورة.',
            'lines.min' => 'يجب إضافة منتج واحد على الأقل للفاتورة.',
            'lines.*.qty.min' => 'الكمية يجب أن تكون أكبر من صفر.',
            'lines.*.width_cm.min' => 'العرض يجب أن يكون 1 سم على الأقل.',
            'lines.*.height_cm.min' => 'الطول يجب أن يكون 1 سم على الأقل.',
            'lines.*.product_id.exists' => 'المنتج المحدد غير موجود.',
            'lines.*.name.required_without' => 'اسم السطر اليدوي مطلوب.',
        ];
    }
}
