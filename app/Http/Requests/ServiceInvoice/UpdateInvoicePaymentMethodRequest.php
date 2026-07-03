<?php

namespace App\Http\Requests\ServiceInvoice;

use App\Models\Branch;
use App\Models\ServiceInvoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        ];
    }

    public function messages(): array
    {
        return [
            'payment_method_id.required' => 'طريقة الدفع مطلوبة.',
            'payment_method_id.in' => 'طريقة الدفع غير متاحة لهذا الفرع.',
        ];
    }
}
