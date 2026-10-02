<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * فلاتر «سجل الدفعات» في شاشة مدفوعات المناديب (تاسك 57).
 *
 * تاسك 164: المدى يُطبَّق على **الفترة المدفوع عنها** (`period_start/period_end`)
 * — دفعةٌ تتداخل فترتها مع المدى تظهر — لا على `paid_at`، بطلب العميل.
 */
class AgentPaymentFilterRequest extends FormRequest
{
    /** الحقول التي يُسمح بالفرز عليها — قائمة مغلقة تمنع حقن اسم عمود. */
    public const SORTABLE = ['paid_at', 'total_rebate', 'total_invoices'];

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
            'agent' => ['nullable', 'integer'],
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
            'agent' => 'المندوب',
        ];
    }

    public function sortColumn(): string
    {
        return in_array($this->input('sort'), self::SORTABLE, true)
            ? (string) $this->input('sort')
            : 'paid_at';
    }

    public function sortDirection(): string
    {
        return $this->input('dir') === 'asc' ? 'asc' : 'desc';
    }
}
