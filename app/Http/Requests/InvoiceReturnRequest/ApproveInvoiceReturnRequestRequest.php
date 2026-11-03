<?php

namespace App\Http\Requests\InvoiceReturnRequest;

use App\Actions\ServiceInvoice\ReturnServiceInvoiceAction;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApproveInvoiceReturnRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * طريقة الردّ إلزامية متى بقي ما يُردّ (قاعدة تاسك 131)، ومن طرق فرع الفاتورة.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $invoice = $this->route('returnRequest')->invoice;
        $refundable = app(ReturnServiceInvoiceAction::class)->refundableCollected($invoice);

        return [
            'payment_method_id' => $refundable > 0
                ? ['required', 'integer', Rule::in($invoice->branch?->enabledPaymentMethods()->pluck('id')->all() ?? [])]
                : ['nullable'],
            // مبلغٌ أقلّ مما حُصِّل = مرتجع جزئي؛ الفارغ = الاسترجاع الكامل.
            'amount' => $refundable > 0 ? ['nullable', 'numeric', 'gt:0', "max:{$refundable}"] : ['prohibited'],
            // تاسك 213: إيصال التحويل متى اشترطته طريقة الردّ.
            'receipt' => [
                PaymentMethod::requiresReceipt($this->input('payment_method_id')) ? 'required' : 'nullable',
                'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_method_id.required' => 'طريقة ردّ المبلغ مطلوبة.',
            'payment_method_id.in' => 'طريقة الردّ غير متاحة لفرع الفاتورة.',
            'amount.gt' => 'مبلغ الردّ يجب أن يكون أكبر من صفر.',
            'amount.max' => 'مبلغ الردّ يتجاوز ما حُصِّل من الفاتورة.',
            'receipt.required' => 'يجب إرفاق إيصال التحويل لطريقة الردّ المحددة.',
            'receipt.mimes' => 'يجب أن يكون الإيصال صورة (jpg, png, webp) أو ملف PDF.',
            'receipt.max' => 'حجم الإيصال يجب ألا يتجاوز 5 ميجابايت.',
        ];
    }
}
