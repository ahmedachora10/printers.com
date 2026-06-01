<?php

namespace App\Http\Requests\ProductInvoice;

use App\Enums\InvoiceStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'status' => ['required', Rule::in([InvoiceStatusEnum::PAID->value, InvoiceStatusEnum::DUE->value])],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty' => ['required', 'integer', 'min:1'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount_pct' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.required' => 'يجب إضافة منتج واحد على الأقل للفاتورة.',
            'lines.min' => 'يجب إضافة منتج واحد على الأقل للفاتورة.',
            'lines.*.qty.min' => 'الكمية يجب أن تكون 1 على الأقل.',
            'lines.*.product_id.exists' => 'المنتج المحدد غير موجود.',
        ];
    }
}
