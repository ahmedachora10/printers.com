<?php

namespace App\Http\Requests\Quotation;

use Illuminate\Foundation\Http\FormRequest;

class RejectQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // السبب اختياري: يُذكر إن عرفه الموظف من العميل.
        return ['reason' => ['nullable', 'string', 'max:1000']];
    }
}
