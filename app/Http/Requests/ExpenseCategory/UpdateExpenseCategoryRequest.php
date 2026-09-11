<?php

namespace App\Http\Requests\ExpenseCategory;

use App\Models\ExpenseCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var ExpenseCategory $category */
        $category = $this->route('expenseCategory');

        // النطاق لا يُنقل بالتعديل، فالتفرّد يُقاس على نطاق الفئة نفسها (تاسك 102).
        $branchId = $category->branch_id;

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('expense_categories', 'name')
                    ->ignore($category->id)
                    ->where(fn ($q) => $branchId === null
                        ? $q->whereNull('branch_id')
                        : $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId))),
            ],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.unique' => 'يوجد فئة مصروف بهذا الاسم متاحة لهذا الفرع بالفعل.',
        ];
    }
}
