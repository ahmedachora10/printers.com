<?php

namespace App\Actions\ProductCategory;

use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;

class CreateProductCategoryAction
{
    public function handle(array $data): ProductCategory
    {
        return DB::transaction(fn () => ProductCategory::create($data));
    }
}
