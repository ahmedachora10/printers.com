<?php

namespace App\Http\Controllers;

use App\Actions\Catalogue\GetActiveCatalogueTreeAction;
use App\Models\Branch;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatalogueController extends Controller
{
    /**
     * Public, no-auth service catalogue. Server-side rendered with the full
     * active category → subcategory → price tree so the page works without
     * an additional client fetch.
     *
     * Prices belong to a branch since تاسك 47, so the page carries a branch
     * picker. With no branch picked it shows the general price list — the
     * safe default for a public page that has no way to know the visitor's
     * branch.
     */
    public function index(Request $request, GetActiveCatalogueTreeAction $tree): Response
    {
        $branches = Branch::query()->active()->orderBy('name')->get(['id', 'name']);

        // Never trust the query string with a branch id: an unknown one falls
        // back to the general list rather than leaking another branch's rows.
        $branchId = $branches->firstWhere('id', (int) $request->input('branch'))?->id;

        return Inertia::render('catalogue/index', [
            'categories' => $tree->handle($branchId),
            'whatsappNumber' => Setting::get('catalogue_whatsapp', null, null),
            'branches' => $branches->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
            ])->values(),
            'selectedBranchId' => $branchId,
        ]);
    }
}
