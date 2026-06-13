<?php

namespace App\Http\Resources\Product;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'categoryId' => $this->category_id,
            'categoryName' => $this->category?->name,
            'unitId' => $this->unit_id,
            'unitName' => $this->unit?->name,
            'costPrice' => (float) $this->cost_price,
            'sellingPrice' => (float) $this->selling_price,
            'minStockLevel' => $this->min_stock_level,
            'currentStock' => $this->current_stock,
            'barcode' => $this->barcode,
            'isActive' => $this->is_active,
            'valuation' => round($this->current_stock * (float) $this->cost_price, 2),
        ];
    }
}
