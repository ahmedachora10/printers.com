<?php

namespace App\Http\Controllers;

use App\Actions\CatalogSubcategory\CreateCatalogSubcategoryAction;
use App\Actions\CatalogSubcategory\DeleteCatalogSubcategoryAction;
use App\Actions\CatalogSubcategory\UpdateCatalogSubcategoryAction;
use App\Http\Requests\CatalogSubcategory\StoreCatalogSubcategoryRequest;
use App\Http\Requests\CatalogSubcategory\UpdateCatalogSubcategoryRequest;
use App\Http\Resources\CatalogCategory\CatalogCategoryResource;
use App\Http\Resources\CatalogSubcategory\CatalogSubcategoryResource;
use App\Models\CatalogCategory;
use App\Models\CatalogSubcategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CatalogSubcategoryController extends Controller
{
    public function index(Request $request, CatalogCategory $category): Response
    {
        Gate::authorize('viewAny', CatalogSubcategory::class);

        $subcategories = $category->subcategories()
            ->withCount('prices')
            ->when($request->input('search'), fn ($q) => $q->where('name_ar', 'like', '%'.$request->input('search').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->input('status')))
            ->ordered()
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('admin/catalogue/subcategories/index', [
            'category' => new CatalogCategoryResource($category),
            'subcategories' => CatalogSubcategoryResource::collection($subcategories),
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
            ],
        ]);
    }

    public function store(StoreCatalogSubcategoryRequest $request, CreateCatalogSubcategoryAction $action): RedirectResponse
    {
        Gate::authorize('create', CatalogSubcategory::class);

        $action->handle($request->validated());

        return back();
    }

    public function update(UpdateCatalogSubcategoryRequest $request, CatalogSubcategory $subcategory, UpdateCatalogSubcategoryAction $action): RedirectResponse
    {
        Gate::authorize('update', $subcategory);

        $action->handle($subcategory, $request->validated());

        return back();
    }

    public function destroy(CatalogSubcategory $subcategory, DeleteCatalogSubcategoryAction $action): RedirectResponse
    {
        Gate::authorize('delete', $subcategory);

        $action->handle($subcategory);

        return back();
    }

    public function toggleStatus(CatalogSubcategory $subcategory, UpdateCatalogSubcategoryAction $action): RedirectResponse
    {
        Gate::authorize('update', $subcategory);

        $action->handle($subcategory, ['is_active' => ! $subcategory->is_active]);

        return back();
    }
}
