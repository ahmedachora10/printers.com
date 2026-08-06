<?php

namespace App\Actions\Catalogue;

use App\Models\CatalogCategory;
use Illuminate\Support\Collection;

class GetActiveCatalogueTreeAction
{
    /**
     * The active category → subcategory → price tree, ordered and already
     * shaped for the front-end. Shared by the public catalogue (M19) and the
     * in-app service price list so both always read the same rows — the
     * catalogue query lives here and nowhere else.
     *
     * Media is eager loaded alongside the relations: `getFirstMediaUrl()`
     * lazy-loads the `media` relation otherwise, which is an N+1 per category
     * and per subcategory.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function handle(): Collection
    {
        return CatalogCategory::query()
            ->active()
            ->ordered()
            ->with([
                'media',
                'subcategories' => fn ($q) => $q->active()->ordered()->with([
                    'media',
                    'prices' => fn ($q) => $q->active()->ordered(),
                ]),
            ])
            ->get()
            ->map(fn (CatalogCategory $category) => [
                'id' => $category->id,
                'nameAr' => $category->name_ar,
                'imageUrl' => $category->getFirstMediaUrl('image') ?: null,
                'subcategories' => $category->subcategories->map(fn ($sub) => [
                    'id' => $sub->id,
                    'nameAr' => $sub->name_ar,
                    'imageUrl' => $sub->getFirstMediaUrl('image') ?: null,
                    'prices' => $sub->prices->map(fn ($price) => [
                        'id' => $price->id,
                        'name' => $price->name,
                        'minPrice' => (float) $price->min_price,
                        'maxPrice' => (float) $price->max_price,
                        'basePrice' => (float) $price->base_price,
                    ])->values(),
                ])->values(),
            ])->values();
    }
}
