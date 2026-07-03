<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class CommissionReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'employee' => ['nullable', 'integer', 'exists:users,id'],
            'branch' => ['nullable', 'integer', 'exists:branches,id'],
            'status' => ['nullable', 'in:all,paid,pending'],
        ];
    }
}
