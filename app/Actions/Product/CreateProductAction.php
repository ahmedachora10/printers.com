<?php

namespace App\Actions\Product;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CreateProductAction
{
    /**
     * صفٌّ مستقلٌّ لكل فرع — المخزون والسعر ملك الفرع (UNIQUE sku, branch_id).
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, int>  $branchIds
     * @return Collection<int, Product>
     */
    public function handle(array $data, array $branchIds): Collection
    {
        $data['min_stock_level'] = $data['min_stock_level'] ?? 0;

        return DB::transaction(fn () => collect($branchIds)->map(fn (int $branchId) => Product::create([
            ...$data,
            'branch_id' => $branchId,
            'sku' => ($data['sku'] ?? null) ?: $this->generateUniqueSku($branchId),
        ])));
    }

    private function generateUniqueSku(int $branchId): string
    {
        $maxId = (int) Product::max('id') + 1;

        // I want to be formated like this "SKU-BRANCHID-00000001"
        return 'SKU-'.$branchId.'-'.str_pad((string) $maxId, 8, '0', STR_PAD_LEFT);
    }
}
