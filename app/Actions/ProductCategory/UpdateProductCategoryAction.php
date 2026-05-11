<?php

namespace App\Actions\ProductCategory;

use App\Models\ProductCategory;

class UpdateProductCategoryAction
{
    public function handle(ProductCategory $productCategory, array $data): ProductCategory
    {
        $productCategory->update(array_filter($data, fn ($value) => $value !== null));

        return $productCategory->fresh();
    }
}
