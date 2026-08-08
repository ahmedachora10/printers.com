<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGeneralSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'app_name' => ['nullable', 'string', 'max:255'],
            'default_vat_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }
}
