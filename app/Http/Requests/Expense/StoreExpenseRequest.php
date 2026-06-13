<?php

namespace App\Http\Requests\Expense;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

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

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'expense_category_id' => ['required', 'integer', 'exists:expense_categories,id'],
            'qty' => ['required', 'numeric', 'min:0.01'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'supplier_name' => ['nullable', 'string', 'max:255'],
            'receipt_reference' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'date' => ['required', 'date'],
        ];
    }
}
