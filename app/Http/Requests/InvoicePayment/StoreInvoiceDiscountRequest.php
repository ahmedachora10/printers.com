<?php

namespace App\Http\Requests\InvoicePayment;

use Illuminate\Foundation\Http\FormRequest;

/**
 * خصمٌ إضافي بلا دفعة. السالب تصحيحٌ لخصمٍ سابق؛ الحدود الفعلية (تحت الإجمالي،
 * فوق المحصَّل) تتحقق منها ApplyInvoiceDiscountAction على صفٍّ مقفول.
 */
class StoreInvoiceDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'not_in:0', 'between:-99999999.99,99999999.99'],
            'confirm_materials_shortage' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'amount.required' => 'أدخل مبلغ الخصم.',
            'amount.numeric' => 'الخصم يجب أن يكون رقماً.',
            'amount.not_in' => 'أدخل مبلغ الخصم.',
        ];
    }
}
