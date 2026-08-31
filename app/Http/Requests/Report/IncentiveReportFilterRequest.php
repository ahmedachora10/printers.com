<?php

namespace App\Http\Requests\Report;

use App\Enums\IncentivePlanStatusEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IncentiveReportFilterRequest extends FormRequest
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
            'employee' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', Rule::enum(IncentivePlanStatusEnum::class)],
        ];
    }
}
