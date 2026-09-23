<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

class SalesReportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * تاسك 124: الفرع يقبل عدّة معرّفات — `?branch=3,7` من الواجهة، أو مصفوفة،
     * أو معرّفاً واحداً (الروابط المحفوظة). كلُّها تُطبَّع إلى قائمة.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('branch')) {
            $raw = $this->input('branch');
            $this->merge(['branch' => array_values(array_filter(
                is_array($raw) ? $raw : explode(',', (string) $raw),
                fn ($value) => is_numeric(trim((string) $value)),
            ))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'branch' => ['nullable', 'array'],
            'branch.*' => ['integer', 'exists:branches,id'],
            'type' => ['nullable', 'in:all,product,service'],
            // تاسك 106: يقرؤه تنزيل الإيصالات وحده.
            'payment_method' => ['nullable', 'integer', 'exists:payment_methods,id'],
        ];
    }
}
