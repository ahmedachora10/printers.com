<?php

namespace App\Http\Requests\Expense;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $branchId = $this->route('expense')->branch_id;

        return [
            // تاسك 102: فئةٌ عامة أو فئة فرع المصروف.
            'expense_category_id' => ['required', 'integer', Rule::exists('expense_categories', 'id')
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))],
            'qty' => ['required', 'numeric', 'min:0.01'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'receipt_reference' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'date' => ['required', 'date'],
        ];
    }
}
