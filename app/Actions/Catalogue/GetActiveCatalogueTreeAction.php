<?php

namespace App\Actions\Catalogue;

use App\Models\CatalogCategory;
use App\Models\CatalogPrice;
use Illuminate\Support\Collection;

class GetActiveCatalogueTreeAction
{
    /**
     * The active category → subcategory → price tree, ordered and already
     * shaped for the front-end. Shared by the public catalogue (M19) and the
     * in-app service price list so both always read the same rows — the
     * catalogue query lives here and nowhere else.
     *
     * Every level is branch-aware (تاسك 47). Passing a branch loads that
     * branch's rows alongside the general ones; passing null yields the
     * general catalogue alone.
     *
     * The tree is **additive**: a branch sees the general categories and
     * subcategories plus the ones it created, and never hides an inherited
     * row. Prices are the one level that **overrides** — a branch price wins
     * over the general one carrying the same name, so a branch overrides only
     * what it actually re-priced.
     *
     * Media is eager loaded alongside the relations: `getFirstMediaUrl()`
     * lazy-loads the `media` relation otherwise, which is an N+1 per category
     * and per subcategory.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function handle(?int $branchId = null): Collection
    {
        return CatalogCategory::query()
            ->active()
            ->forBranch($branchId)
            ->ordered()
            ->with([
                'media',
                'subcategories' => fn ($q) => $q->active()->forBranch($branchId)->ordered()->with([
                    'media',
                    'prices' => fn ($q) => $q->active()->forBranch($branchId)->ordered(),
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
                    'prices' => $this->resolveOverrides($sub->prices, $branchId)->map(fn (CatalogPrice $price) => [
                        'id' => $price->id,
                        'name' => $price->name,
                        'minPrice' => (float) $price->min_price,
                        'maxPrice' => (float) $price->max_price,
                        'basePrice' => (float) $price->base_price,
                        'isBranchPrice' => $price->branch_id !== null,
                    ])->values(),
                ])->values(),
            ])->values();
    }

    /**
     * Collapse the general/branch pair down to one row per name, the branch
     * row winning. Done in PHP rather than SQL because the fallback is a
     * per-name decision and the price set of one subcategory is tiny — a
     * window function here would cost a second query per subcategory.
     *
     * @param  Collection<int, CatalogPrice>  $prices
     * @return Collection<int, CatalogPrice>
     */
    private function resolveOverrides(Collection $prices, ?int $branchId): Collection
    {
        if ($branchId === null) {
            return $prices;
        }

        return $prices
            ->groupBy('name')
            ->map(fn (Collection $sameName) => $sameName->firstWhere('branch_id', $branchId) ?? $sameName->first())
            ->values()
            // groupBy scrambles the `ordered()` sort, so re-apply it.
            ->sortBy([['sort_order', 'asc'], ['name', 'asc']])
            ->values();
    }
}
