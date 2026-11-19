<?php

namespace App\Actions\ExpenseCategory;

use App\Models\ExpenseCategory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateExpenseCategoryAction
{
    /** @param array<string, mixed> $data */
    public function handle(ExpenseCategory $expenseCategory, array $data): ExpenseCategory
    {
        DB::transaction(function () use ($expenseCategory, $data) {
            $expenseCategory->update(Arr::except($data, 'branch_ids'));

            // تاسك 238: [] = عامة؛ تبديل الحالة لا يرسل branch_ids فلا يمسّها.
            if (array_key_exists('branch_ids', $data)) {
                $expenseCategory->branches()->sync($data['branch_ids']);
            }
        });

        return $expenseCategory->fresh();
    }
}
