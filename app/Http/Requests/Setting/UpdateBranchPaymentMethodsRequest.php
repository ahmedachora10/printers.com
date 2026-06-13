<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBranchPaymentMethodsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled_ids' => ['required', 'array'],
            'enabled_ids.*' => ['integer', 'exists:payment_methods,id'],
        ];
    }
}
