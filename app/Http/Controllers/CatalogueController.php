<?php

namespace App\Http\Controllers;

use App\Models\CatalogCategory;
use App\Models\Setting;
use Inertia\Inertia;
use Inertia\Response;

class CatalogueController extends Controller
{
    /**
     * Public, no-auth service catalogue. Server-side rendered with the full
     * active category → subcategory → price tree so the page works without
     * an additional client fetch.
     */
    public function index(): Response
    {
        $categories = CatalogCategory::query()
            ->active()
            ->ordered()
            ->with([
                'subcategories' => fn ($q) => $q->active()->ordered()->with([
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

        return Inertia::render('catalogue/index', [
            'categories' => $categories,
            'whatsappNumber' => Setting::get('catalogue_whatsapp', null, null),
        ]);
    }
}
