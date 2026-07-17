<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class DailyReportFilterRequest extends FormRequest
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
            'branch' => ['nullable', 'integer', 'exists:branches,id'],
            'employee' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
