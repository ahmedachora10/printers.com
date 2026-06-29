<?php

namespace App\Http\Resources\CatalogSubcategory;

use App\Models\CatalogSubcategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CatalogSubcategory
 */
class CatalogSubcategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nameAr' => $this->name_ar,
            'categoryId' => $this->category_id,
            'sortOrder' => $this->sort_order,
            'isActive' => $this->is_active,
            'imageUrl' => $this->getFirstMediaUrl('image') ?: null,
            'pricesCount' => $this->whenCounted('prices'),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
