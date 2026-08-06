<?php

namespace App\Http\Controllers;

use App\Actions\Catalogue\GetActiveCatalogueTreeAction;
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
    public function index(GetActiveCatalogueTreeAction $tree): Response
    {
        return Inertia::render('catalogue/index', [
            'categories' => $tree->handle(),
            'whatsappNumber' => Setting::get('catalogue_whatsapp', null, null),
        ]);
    }
}
