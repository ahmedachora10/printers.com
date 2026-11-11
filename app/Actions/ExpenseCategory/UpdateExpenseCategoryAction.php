<?php

namespace App\Actions\ExpenseCategory;

use App\Models\ExpenseCategory;

class UpdateExpenseCategoryAction
{
    /** @param array<string, mixed> $data */
    public function handle(ExpenseCategory $expenseCategory, array $data): ExpenseCategory
    {
        // بلا تصفية الفارغ: branch_id = null هو «عامة» (تاسك 231).
        $expenseCategory->update($data);

        return $expenseCategory->fresh();
    }
}
