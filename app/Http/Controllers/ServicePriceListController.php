<?php

namespace App\Http\Controllers;

use App\Actions\Catalogue\GetActiveCatalogueTreeAction;
use App\Models\Branch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ServicePriceListController extends Controller
{
    /**
     * Read-only, in-app price list for staff. Same active catalogue tree the
     * public page (M19) renders — only the presentation differs, so the query
     * is shared through GetActiveCatalogueTreeAction rather than duplicated.
     *
     * Staff always read their own branch's prices (تاسك 47): the branch is
     * taken from the user, never from the request. The super admin belongs to
     * no branch, so they alone get a picker — and see the general list until
     * they pick.
     */
    public function index(Request $request, GetActiveCatalogueTreeAction $tree): Response
    {
        $user = $request->user();
        $isSuperAdmin = (bool) $user->roleName?->isSuperAdmin();

        $branches = $isSuperAdmin
            ? Branch::query()->active()->orderBy('name')->get(['id', 'name'])
            : null;

        $branchId = $isSuperAdmin
            ? $branches->firstWhere('id', (int) $request->input('branch'))?->id
            : $user->branch_id;

        return Inertia::render('services/price-list', [
            'categories' => $tree->handle($branchId),
            'branches' => $branches?->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
            ])->values(),
            'selectedBranchId' => $branchId,
        ]);
    }
}
