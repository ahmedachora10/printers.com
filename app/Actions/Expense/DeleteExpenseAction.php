<?php

namespace App\Actions\Expense;

use App\Models\Expense;

class DeleteExpenseAction
{
    public function handle(Expense $expense): ?bool
    {
        return $expense->delete();
    }
}
