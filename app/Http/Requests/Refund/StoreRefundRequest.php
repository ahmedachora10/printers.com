<?php

namespace App\Http\Requests\Refund;

use App\Enums\InvoiceTypeEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'source_type' => ['required', 'string', Rule::in(InvoiceTypeEnum::all())],
            'invoice_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'max:1000'],
            // فواتير المنتجات تعيد بضاعتها، وفواتير الخدمات تعيد خاماتها.
            'reverse_stock' => ['nullable', 'boolean'],
            // تاسك 93: هل تُردّ قيمة التوصيل للعميل؟ قرارُ المحاسب لكل حالة،
            // ويُكتب على صفّ المرتجع بدل أن يُستنتج من المبلغ.
            'refund_shipping' => ['nullable', 'boolean'],
            // تاسك 131: بأيّ طريقة رُدّ المبلغ — منها يُطرح في تقرير المبيعات.
            'payment_method_id' => ['required', 'integer', Rule::in($this->enabledPaymentMethodIds())],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'سبب الإرجاع مطلوب.',
            'amount.min' => 'مبلغ المرتجع يجب أن يكون أكبر من صفر.',
            'payment_method_id.required' => 'طريقة ردّ المبلغ مطلوبة.',
            'payment_method_id.in' => 'طريقة الردّ غير متاحة لفرع الفاتورة.',
        ];
    }

    /**
     * طرق فرع الفاتورة المفعّلة — نفس قاعدة الدفعات (StoreInvoicePaymentRequest).
     *
     * @return array<int, int>
     */
    private function enabledPaymentMethodIds(): array
    {
        $invoice = InvoiceTypeEnum::tryFrom((string) $this->input('source_type'))
            ?->modelClass()::find($this->input('invoice_id'));

        return $invoice?->branch?->enabledPaymentMethods()->pluck('id')->all() ?? [];
    }
}
