<?php

namespace App\Http\Controllers;

use App\Actions\CatalogCategory\CreateCatalogCategoryAction;
use App\Actions\CatalogCategory\DeleteCatalogCategoryAction;
use App\Actions\CatalogCategory\UpdateCatalogCategoryAction;
use App\Exports\CatalogueExport;
use App\Http\Requests\CatalogCategory\StoreCatalogCategoryRequest;
use App\Http\Requests\CatalogCategory\UpdateCatalogCategoryRequest;
use App\Http\Resources\CatalogCategory\CatalogCategoryResource;
use App\Imports\CatalogueImport;
use App\Models\CatalogCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CatalogCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', CatalogCategory::class);

        $categories = CatalogCategory::query()
            ->withCount('subcategories')
            ->when($request->input('search'), fn ($q) => $q->where('name_ar', 'like', '%'.$request->input('search').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', $request->input('status')))
            ->ordered()
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('admin/catalogue/categories/index', [
            'categories' => CatalogCategoryResource::collection($categories),
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
            ],
        ]);
    }

    public function store(StoreCatalogCategoryRequest $request, CreateCatalogCategoryAction $action): RedirectResponse
    {
        Gate::authorize('create', CatalogCategory::class);

        $action->handle($request->validated());

        return back(fallback: route('admin.catalogue.categories.index'));
    }

    public function update(UpdateCatalogCategoryRequest $request, CatalogCategory $category, UpdateCatalogCategoryAction $action): RedirectResponse
    {
        Gate::authorize('update', $category);

        $action->handle($category, $request->validated());

        return back(fallback: route('admin.catalogue.categories.index'));
    }

    public function destroy(CatalogCategory $category, DeleteCatalogCategoryAction $action): RedirectResponse
    {
        Gate::authorize('delete', $category);

        $action->handle($category);

        return back(fallback: route('admin.catalogue.categories.index'));
    }

    public function toggleStatus(CatalogCategory $category, UpdateCatalogCategoryAction $action): RedirectResponse
    {
        Gate::authorize('update', $category);

        $action->handle($category, ['is_active' => ! $category->is_active]);

        return back(fallback: route('admin.catalogue.categories.index'));
    }

    /**
     * Export the whole catalogue (categories → subcategories → prices) as a
     * single flat Excel sheet.
     */
    public function export(): BinaryFileResponse
    {
        Gate::authorize('viewAny', CatalogCategory::class);

        return Excel::download(new CatalogueExport, 'catalogue-'.now()->format('Y-m-d').'.xlsx');
    }

    /**
     * Import a full-catalogue sheet. Upsert-only: creates or updates
     * categories, subcategories and prices; never deletes.
     */
    public function import(Request $request): RedirectResponse
    {
        Gate::authorize('create', CatalogCategory::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv'],
        ]);

        Excel::import(new CatalogueImport, $request->file('file'));

        return back(fallback: route('admin.catalogue.categories.index'));
    }
}
