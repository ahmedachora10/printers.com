<?php

namespace App\Actions\ExpenseCategory;

use App\Models\ExpenseCategory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateExpenseCategoryAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): ExpenseCategory
    {
        return DB::transaction(function () use ($data) {
            $category = ExpenseCategory::create(Arr::except($data, 'branch_ids'));
            $category->branches()->sync($data['branch_ids'] ?? []);

            return $category;
        });
    }
}
