<?php

namespace App\Actions\ProductCategory;

use App\Models\ProductCategory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class UpdateProductCategoryAction
{
    /** @param array<string, mixed> $data */
    public function handle(ProductCategory $productCategory, array $data): ProductCategory
    {
        DB::transaction(function () use ($productCategory, $data) {
            $productCategory->update(array_filter(Arr::except($data, 'branch_ids'), fn ($value) => $value !== null));

            // تاسك 237: [] = عامة؛ تبديل الحالة لا يرسل branch_ids فلا يمسّها.
            if (array_key_exists('branch_ids', $data)) {
                $productCategory->branches()->sync($data['branch_ids']);
            }
        });

        return $productCategory->fresh();
    }
}
