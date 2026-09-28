<?php

namespace App\Http\Requests\InvoiceReturnRequest;

use Illuminate\Foundation\Http\FormRequest;

class RejectInvoiceReturnRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'rejection_reason.required' => 'سبب الرفض مطلوب.',
            'rejection_reason.max' => 'سبب الرفض طويل جداً.',
        ];
    }
}
