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
     * The employee filter accepts several ids. The UI sends them as a single
     * comma-separated value (`?employee=3,7`) so the string-based report filter
     * hook can carry it, but a plain `employee[]=3&employee[]=7` array and a
     * legacy single id both normalize to the same list. Non-numeric leftovers
     * (an older `?employee=all` link) are dropped rather than rejected.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('employee')) {
            return;
        }

        $raw = $this->input('employee');
        $values = is_array($raw) ? $raw : explode(',', (string) $raw);

        $this->merge([
            'employee' => collect($values)
                ->map(fn ($value) => trim((string) $value))
                ->filter(fn (string $value) => is_numeric($value))
                ->unique()
                ->values()
                ->all(),
        ]);
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
            'employee' => ['nullable', 'array'],
            'employee.*' => ['integer', 'exists:users,id'],
        ];
    }
}
