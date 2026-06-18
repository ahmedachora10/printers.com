<?php

namespace App\Http\Requests\ServiceInvoice;

use Illuminate\Foundation\Http\FormRequest;

class CancelServiceInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'يجب ذكر سبب الإلغاء.',
            'reason.min' => 'سبب الإلغاء قصير جداً.',
        ];
    }
}
