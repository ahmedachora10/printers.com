<?php

namespace App\Actions\ExpenseCategory;

use App\Models\ExpenseCategory;

class UpdateExpenseCategoryAction
{
    /** @param array<string, mixed> $data */
    public function handle(ExpenseCategory $expenseCategory, array $data): ExpenseCategory
    {
        $expenseCategory->update(array_filter($data, fn ($value) => $value !== null));

        return $expenseCategory->fresh();
    }
}
