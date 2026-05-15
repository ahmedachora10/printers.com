<?php

namespace App\Actions\Product;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DeleteProductAction
{
    public function handle(Product $product): bool
    {
        if (
            Schema::hasTable('product_invoice_lines') &&
            DB::table('product_invoice_lines')->where('product_id', $product->id)->exists()
        ) {
            throw ValidationException::withMessages([
                'product' => 'لا يمكن حذف منتج مرتبط بفواتير.',
            ]);
        }

        return DB::transaction(fn () => $product->delete());
    }
}
