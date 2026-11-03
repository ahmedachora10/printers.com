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
            // تاسك 214: الشريحة المصروفة؛ بلا رقم = الأولى (خطة الشريحة الواحدة).
            'tier' => ['nullable', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
