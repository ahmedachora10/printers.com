<?php

namespace App\Http\Controllers;

use App\Actions\Catalogue\GetActiveCatalogueTreeAction;
use Inertia\Inertia;
use Inertia\Response;

class ServicePriceListController extends Controller
{
    /**
     * Read-only, in-app price list for staff. Same active catalogue tree the
     * public page (M19) renders — only the presentation differs, so the query
     * is shared through GetActiveCatalogueTreeAction rather than duplicated.
     */
    public function index(GetActiveCatalogueTreeAction $tree): Response
    {
        return Inertia::render('services/price-list', [
            'categories' => $tree->handle(),
        ]);
    }
}
