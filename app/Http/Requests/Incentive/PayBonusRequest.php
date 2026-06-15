<?php

namespace App\Http\Requests\Incentive;

use Illuminate\Foundation\Http\FormRequest;

class PayBonusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
