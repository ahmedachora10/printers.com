<?php

namespace App\Actions\ProductCategory;

use App\Models\ProductCategory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateProductCategoryAction
{
    /** @param array<string, mixed> $data */
    public function handle(array $data): ProductCategory
    {
        return DB::transaction(function () use ($data) {
            $category = ProductCategory::create(Arr::except($data, 'branch_ids'));
            $category->branches()->sync($data['branch_ids'] ?? []);

            return $category;
        });
    }
}
