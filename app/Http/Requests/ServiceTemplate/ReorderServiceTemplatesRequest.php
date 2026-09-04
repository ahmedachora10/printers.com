<?php

namespace App\Http\Requests\ServiceTemplate;

use Illuminate\Foundation\Http\FormRequest;

class ReorderServiceTemplatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:service_templates,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ids.required' => 'لم يصل ترتيب جديد.',
        ];
    }
}
