<?php

namespace App\Actions\ExpenseCategory;

use App\Models\ExpenseCategory;
use Illuminate\Support\Facades\DB;

class CreateExpenseCategoryAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): ExpenseCategory
    {
        return DB::transaction(fn () => ExpenseCategory::create($data));
    }
}
