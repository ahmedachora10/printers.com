<?php

namespace App\Http\Requests\ServiceTemplate;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServiceTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active'   => ['boolean'],
        ];
    }
}
