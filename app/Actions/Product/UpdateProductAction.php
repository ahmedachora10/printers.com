<?php

namespace App\Actions\Product;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

class UpdateProductAction
{
    public function handle(Product $product, array $data): Product
    {
        if (isset($data['sku']) && empty($data['sku'])) {
            unset($data['sku']);
        }

        return DB::transaction(function () use ($product, $data) {
            $product->update($data);

            return $product->refresh();
        });
    }
}
