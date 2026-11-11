<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class AgentCommissionReportFilterRequest extends FormRequest
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
            // تاسك 233: «3,7» — ResolveReportScope يحلّلها ويثبّت غير السوبر أدمن على فرعه.
            'branch' => ['nullable', 'string'],
            'agent' => ['nullable', 'integer', 'exists:users,id'],
            'user' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
