<?php

namespace App\Http\Requests\InvoiceReturnRequest;

use App\Actions\ServiceInvoice\ReturnServiceInvoiceAction;
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

        return [
            'payment_method_id' => app(ReturnServiceInvoiceAction::class)->refundableCollected($invoice) > 0
                ? ['required', 'integer', Rule::in($invoice->branch?->enabledPaymentMethods()->pluck('id')->all() ?? [])]
                : ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'payment_method_id.required' => 'طريقة ردّ المبلغ مطلوبة.',
            'payment_method_id.in' => 'طريقة الردّ غير متاحة لفرع الفاتورة.',
        ];
    }
}
