<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * فلاتر «سجل الدفعات» في شاشة مدفوعات المناديب (تاسك 57).
 *
 * المدى التاريخي يُطبَّق على `paid_at` — يوم صرف الدفعة — لا على تواريخ فواتير
 * الفترة المسدَّدة: خلط الاثنين يُنتج مجاميع لا تُطابق أي تسوية، لأن دفعةً
 * تُصرف في أغسطس قد تغطي فواتير يوليو.
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
            : 'paid_at';
    }

    public function sortDirection(): string
    {
        return $this->input('dir') === 'asc' ? 'asc' : 'desc';
    }
}
