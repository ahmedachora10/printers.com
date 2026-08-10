<?php

namespace App\Http\Requests\InvoicePayment;

use App\Enums\InvoiceTypeEnum;
use App\Models\Branch;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * دفعة جديدة على فاتورة (عربون أو دفعة لاحقة). المبلغ موجب دائماً من الواجهة —
 * التصحيح بدفعة سالبة يمرّ من RecordInvoicePaymentAction مباشرةً، لا من هنا.
 * سقف المبلغ (المتبقي) تتحقق منه نفس الـ Action داخل المعاملة، حيث يكون صف
 * الفاتورة مقفلاً فلا تتسلل دفعتان متزامنتان فوق الإجمالي.
 *
 * طريقة الدفع **إلزامية**: دفعة بلا طريقة تسقط من تفصيل طرق الدفع في التقارير،
 * فيظهر المحصَّل ناقصاً بلا سبب ظاهر. والإيصال إلزامي كذلك متى اشترطته الطريقة
 * المختارة — نفس قاعدة StoreServiceInvoiceRequest، مطبَّقة على الدفعة هذه المرة.
 */
class StoreInvoicePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'payment_method_id' => ['required', 'integer', Rule::in($this->enabledPaymentMethodIds())],
            'receipt' => [
                $this->paymentMethodRequiresAttachment() ? 'required' : 'nullable',
                'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120',
            ],
            'paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.required' => 'مبلغ الدفعة مطلوب.',
            'amount.numeric' => 'مبلغ الدفعة يجب أن يكون رقماً.',
            'amount.min' => 'مبلغ الدفعة يجب أن يكون أكبر من صفر.',
            'payment_method_id.required' => 'طريقة الدفع مطلوبة.',
            'payment_method_id.in' => 'طريقة الدفع غير متاحة لهذا الفرع.',
            'receipt.required' => 'يجب إرفاق إيصال التحويل لطريقة الدفع المحددة.',
            'receipt.mimes' => 'يجب أن يكون الإيصال صورة (jpg, png, webp) أو ملف PDF.',
            'receipt.max' => 'حجم الإيصال يجب ألا يتجاوز 5 ميجابايت.',
            'paid_at.date' => 'تاريخ الدفعة غير صالح.',
            'notes.max' => 'الملاحظة يجب ألا تتجاوز 500 حرف.',
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

    /**
     * The payment methods enabled for the invoice's own branch — a payment may
     * be collected by a different method than the invoice was raised with.
     *
     * @return array<int, int>
     */
    private function enabledPaymentMethodIds(): array
    {
        $type = InvoiceTypeEnum::tryFrom((string) $this->route('type'));

        if ($type === null) {
            return [];
        }

        $invoice = $type->modelClass()::find($this->route('id'));
        $branch = $invoice ? Branch::find($invoice->branch_id) : null;

        return $branch ? $branch->enabledPaymentMethods()->pluck('id')->all() : [];
    }
}
