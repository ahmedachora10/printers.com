<?php

namespace App\Http\Requests\ExpenseCategory;

use App\Models\ExpenseCategory;
use Illuminate\Validation\Rule;

/** رسائل الإنشاء نفسها؛ `branch_id` المدموج هناك لا قاعدة له هنا فلا يُحفظ. */
class UpdateExpenseCategoryRequest extends StoreExpenseCategoryRequest
{
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
}
