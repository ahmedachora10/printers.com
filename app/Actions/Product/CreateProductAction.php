<?php

namespace App\Actions\Product;

use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateProductAction
{
    public function handle(array $data): Product
    {
        $user = auth()->user();
        $data['branch_id']       = $user->branchId;
        $data['min_stock_level'] = $data['min_stock_level'] ?? 0;

        return DB::transaction(function () use ($data) {

            if (empty($data['sku'])) {
                $data['sku'] = $this->generateUniqueSku($data['branch_id']);
            }
            return Product::create($data);
        });
    }

    private function generateUniqueSku(int $branchId): string
    {
        $maxId = (int) Product::max('id') + 1;
        // I want to be formated like this "SKU-BRANCHID-00000001"
        return 'SKU-' . $branchId . '-' . str_pad($maxId, 8, '0', STR_PAD_LEFT);
    }
}
