<?php

namespace App\Http\Controllers;

use App\Actions\CatalogPrice\CreateCatalogPriceAction;
use App\Actions\CatalogPrice\DeleteCatalogPriceAction;
use App\Actions\CatalogPrice\UpdateCatalogPriceAction;
use App\Exports\CatalogPricesExport;
use App\Http\Controllers\Concerns\ResolvesCatalogueScope;
use App\Http\Requests\CatalogPrice\StoreCatalogPriceRequest;
use App\Http\Requests\CatalogPrice\UpdateCatalogPriceRequest;
use App\Http\Resources\CatalogPrice\CatalogPriceResource;
use App\Http\Resources\CatalogSubcategory\CatalogSubcategoryResource;
use App\Imports\CatalogPricesImport;
use App\Models\CatalogPrice;
use App\Models\CatalogSubcategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CatalogPriceController extends Controller
{
    use ResolvesCatalogueScope;

    public function index(Request $request, CatalogSubcategory $subcategory): Response
    {
        Gate::authorize('viewAny', CatalogPrice::class);

        $subcategory->load('category');

        $prices = $subcategory->prices()
            ->with('branch:id,name')
            ->tap(fn ($q) => $this->scopeCatalogueQuery($q, $request))
            ->when($request->input('search'), fn ($q) => $q->where('name', 'like', '%'.$request->input('search').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->input('status')))
            ->ordered()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('admin/catalogue/prices/index', [
            'subcategory' => new CatalogSubcategoryResource($subcategory),
            'prices' => CatalogPriceResource::collection($prices),
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'branch' => $request->input('branch'),
            ],
            'branches' => $this->cataloguePickerBranches($request),
            'ownBranchId' => $this->isCatalogueSuperAdmin($request) ? null : $this->catalogueWriteScope($request),
        ]);
    }

    public function store(StoreCatalogPriceRequest $request, CreateCatalogPriceAction $action): RedirectResponse
    {
        Gate::authorize('create', CatalogPrice::class);

        $action->handle($request->validated());

        return back();
    }

    public function update(UpdateCatalogPriceRequest $request, CatalogPrice $price, UpdateCatalogPriceAction $action): RedirectResponse
    {
        Gate::authorize('update', $price);

        $action->handle($price, $request->validated());

        return back();
    }

    public function destroy(CatalogPrice $price, DeleteCatalogPriceAction $action): RedirectResponse
    {
        Gate::authorize('delete', $price);

        $action->handle($price);

        return back();
    }

    public function toggleStatus(CatalogPrice $price, UpdateCatalogPriceAction $action): RedirectResponse
    {
        Gate::authorize('update', $price);

        $action->handle($price, ['is_active' => ! $price->is_active]);

        return back();
    }

    public function export(Request $request, CatalogSubcategory $subcategory): BinaryFileResponse
    {
        Gate::authorize('viewAny', CatalogPrice::class);

        $branchId = $this->catalogueWriteScope($request);

        $filename = 'catalog-prices-'.$subcategory->id.($branchId ? '-branch-'.$branchId : '').'.xlsx';

        return Excel::download(new CatalogPricesExport($subcategory->id, $branchId), $filename);
    }

    public function import(Request $request, CatalogSubcategory $subcategory): RedirectResponse
    {
        Gate::authorize('create', CatalogPrice::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        Excel::import(new CatalogPricesImport($subcategory->id, $this->catalogueWriteScope($request)), $request->file('file'));

        return back();
    }
}
