<?php

namespace App\Http\Requests\ServiceInvoice;

use Illuminate\Foundation\Http\FormRequest;

class ReturnServiceInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The reason is optional — the action falls back to a default one naming the
     * invoice, so a return is never blocked on wording.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.max' => 'سبب الاسترجاع طويل جداً.',
        ];
    }
}
