<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class MaterialsReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch' => ['nullable', 'integer', 'exists:branches,id'],
            'product' => ['nullable', 'integer', 'exists:products,id'],
            'service' => ['nullable', 'integer', 'exists:branch_services,id'],
        ];
    }
}
