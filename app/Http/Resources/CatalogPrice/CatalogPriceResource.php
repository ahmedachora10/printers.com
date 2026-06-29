<?php

namespace App\Http\Resources\CatalogPrice;

use App\Models\CatalogPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CatalogPrice
 */
class CatalogPriceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subcategoryId' => $this->subcategory_id,
            'name' => $this->name,
            'minPrice' => (float) $this->min_price,
            'maxPrice' => (float) $this->max_price,
            'basePrice' => (float) $this->base_price,
            'sortOrder' => $this->sort_order,
            'isActive' => $this->is_active,
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
