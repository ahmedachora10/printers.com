<?php

namespace App\Http\Requests\ServiceInvoice;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * فلاتر طابور عروض الأسعار (تاسك 60).
 *
 * المدى التاريخي على `created_at` — يوم تحرير الموظف للعرض — لا على `paid_at`:
 * الطابور لا يحمل إلا فواتير آجلة لم تُعتمد بعد، فلا تاريخ اعتماد لها أصلاً.
 */
class ReviewQueueFilterRequest extends FormRequest
{
    /** الحقول التي يُسمح بالفرز عليها — قائمة مغلقة تمنع حقن اسم عمود. */
    public const SORTABLE = ['created_at', 'total_amount'];

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
            'search' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'from' => 'من تاريخ',
            'to' => 'إلى تاريخ',
        ];
    }

    public function sortColumn(): string
    {
        return in_array($this->input('sort'), self::SORTABLE, true)
            ? (string) $this->input('sort')
            : 'created_at';
    }

    public function sortDirection(): string
    {
        return $this->input('dir') === 'asc' ? 'asc' : 'desc';
    }
}
