<?php

namespace App\Http\Resources\CatalogCategory;

use App\Models\CatalogCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CatalogCategory
 */
class CatalogCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nameAr' => $this->name_ar,
            'branchId' => $this->branch_id,
            // Null branch = general row every branch inherits (تاسك 47).
            'branchName' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'sortOrder' => $this->sort_order,
            'isActive' => $this->is_active,
            'imageUrl' => $this->getFirstMediaUrl('image') ?: null,
            'subcategoriesCount' => $this->whenCounted('subcategories'),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
