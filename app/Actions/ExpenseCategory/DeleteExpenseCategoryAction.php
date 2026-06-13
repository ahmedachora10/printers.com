<?php

namespace App\Actions\ExpenseCategory;

use App\Models\ExpenseCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DeleteExpenseCategoryAction
{
    public function handle(ExpenseCategory $expenseCategory): bool
    {
        if (
            Schema::hasTable('expenses') &&
            DB::table('expenses')->where('expense_category_id', $expenseCategory->id)->exists()
        ) {
            throw ValidationException::withMessages([
                'expense_category' => 'لا يمكن حذف فئة مرتبطة بمصروفات.',
            ]);
        }

        return $expenseCategory->delete();
    }
}
