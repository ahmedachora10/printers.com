<?php

namespace App\Http\Requests\ServiceInvoice;

use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\ServiceInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * تعديل طريقة دفع فاتورة معلّقة من شاشة المراجعة أو من صفحة الفاتورة.
 *
 * الإيصال إلزامي هنا كما هو إلزامي في نقطة البيع: كان هذا المسار ثغرةً تُبدَّل
 * منها الطريقة إلى «تحويل بنكي» بلا إثبات، فتُعتمد الفاتورة بعدها بلا مرفق —
 * وهو أكثر مسارات المحاسب استعمالاً. ولا يُطلب متى كانت الفاتورة تحمل إيصالاً
 * أصلاً؛ عندها يكون الرفع استبدالاً اختيارياً.
 */
class UpdateInvoicePaymentMethodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var ServiceInvoice $invoice */
        $invoice = $this->route('invoice');

        $branch = Branch::find($invoice->branch_id);
        $enabledIds = $branch
            ? $branch->enabledPaymentMethods()->pluck('id')->all()
            : [];

        return [
            'payment_method_id' => ['required', 'integer', Rule::in($enabledIds)],
            'receipt' => [
                $this->receiptRequired($invoice) ? 'required' : 'nullable',
                'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120',
            ],
        ];
    }

    /**
     * هل تُلزِم الطريقةُ المختارة بإيصالٍ لم تحمله الفاتورة بعد؟
     */
    private function receiptRequired(ServiceInvoice $invoice): bool
    {
        $id = $this->input('payment_method_id');
        $requires = $id !== null && (bool) PaymentMethod::find($id)?->requires_attachment;

        return $requires && ! $invoice->hasReceipt();
    }

    public function messages(): array
    {
        return [
            'payment_method_id.required' => 'طريقة الدفع مطلوبة.',
            'payment_method_id.in' => 'طريقة الدفع غير متاحة لهذا الفرع.',
            'receipt.required' => 'يجب إرفاق إيصال التحويل لطريقة الدفع المحددة.',
            'receipt.mimes' => 'يجب أن يكون الإيصال صورة (jpg, png, webp) أو ملف PDF.',
            'receipt.max' => 'حجم الإيصال يجب ألا يتجاوز 5 ميجابايت.',
        ];
    }
}
