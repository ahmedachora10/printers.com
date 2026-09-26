<?php

namespace App\Http\Requests\Expense;

use App\Enums\ExpenseSourceEnum;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $user = Auth::user();

        // Non super-admins always book expenses against their own branch;
        // only super-admin chooses the branch from the form.
        if (! $user->roleName?->isSuperAdmin()) {
            $this->merge(['branch_id' => $user->branchId]);
        }
    }

    public static function canBackdate(): bool
    {
        $role = Auth::user()->roleName;

        return (bool) ($role?->isSuperAdmin() || $role?->isBranchAdmin());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            // تاسك 102: فئةٌ عامة أو فئة فرع المصروف — لا فئةَ فرعٍ آخر ولو أُرسل معرّفها.
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $this->input('branch_id')))],
            'qty' => ['required', 'numeric', 'min:0.01'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            // تاسك 110: إلزاميّ — النقدي وحده يُطرح من «المتبقي من النقد».
            'paid_from' => ['required', Rule::enum(ExpenseSourceEnum::class)],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'receipt_reference' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:1000'],
            // تاسك 112: مستند إثبات + ربطٌ اختياري بطلبٍ من فرع المصروف نفسه.
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'service_invoice_id' => ['nullable', 'integer', Rule::exists('service_invoices', 'id')->where('branch_id', $this->input('branch_id'))->whereNull('deleted_at')],
            // تاسك 135: التاريخ القديم لمدير الفرع ومدير النظام فقط.
            'date' => ['required', 'date', Rule::when(! self::canBackdate(), ['after_or_equal:today'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['date.after_or_equal' => 'تسجيل مصروف بتاريخ قديم متاح لمدير الفرع ومدير النظام فقط.'];
    }
}
