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
            // Only meaningful for product refunds; ignored otherwise.
            'reverse_stock' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'سبب الإرجاع مطلوب.',
            'amount.min' => 'مبلغ المرتجع يجب أن يكون أكبر من صفر.',
        ];
    }
}
