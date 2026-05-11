<?php

namespace App\Actions\ProductCategory;

use App\Models\ProductCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DeleteProductCategoryAction
{
    public function handle(ProductCategory $productCategory): bool
    {
        if (
            Schema::hasTable('products') &&
            DB::table('products')->where('category_id', $productCategory->id)->exists()
        ) {
            throw ValidationException::withMessages([
                'product_category' => 'لا يمكن حذف فئة مرتبطة بمنتجات.',
            ]);
        }

        return $productCategory->delete();
    }
}
