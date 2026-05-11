<?php

namespace App\Http\Controllers;

use App\Actions\ProductCategory\CreateProductCategoryAction;
use App\Actions\ProductCategory\DeleteProductCategoryAction;
use App\Actions\ProductCategory\UpdateProductCategoryAction;
use App\Http\Requests\ProductCategory\StoreProductCategoryRequest;
use App\Http\Requests\ProductCategory\UpdateProductCategoryRequest;
use App\Http\Resources\ProductCategory\ProductCategoryResource;
use App\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;


class ProductCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', ProductCategory::class);

        $items = ProductCategory::query()
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%' . $request->input('search') . '%'))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', (bool) $request->input('status')))
            ->orderBy('name')
            ->paginate(15);

        return Inertia::render('product-categories/index', [
            'items'   => ProductCategoryResource::collection($items),
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
            ],
        ]);
    }

    public function store(StoreProductCategoryRequest $request, CreateProductCategoryAction $action): RedirectResponse
    {
        Gate::authorize('create', ProductCategory::class);

        $action->handle($request->validated());

        return to_route('product-categories.index')->with('success', 'تم إنشاء فئة المنتج بنجاح');
    }

    public function update(UpdateProductCategoryRequest $request, ProductCategory $productCategory, UpdateProductCategoryAction $action): RedirectResponse
    {
        Gate::authorize('update', $productCategory);

        $action->handle($productCategory, $request->validated());

        return to_route('product-categories.index')->with('success', 'تم تحديث فئة المنتج بنجاح');
    }

    public function destroy(ProductCategory $productCategory, DeleteProductCategoryAction $action): RedirectResponse
    {
        Gate::authorize('delete', $productCategory);

        $action->handle($productCategory);

        return to_route('product-categories.index')->with('success', 'تم حذف فئة المنتج بنجاح');
    }

    public function toggleStatus(ProductCategory $productCategory, UpdateProductCategoryAction $action): RedirectResponse
    {
        Gate::authorize('update', $productCategory);

        $action->handle($productCategory, ['is_active' => ! $productCategory->is_active]);

        return to_route('product-categories.index')->with('success', 'تم تحديث حالة فئة المنتج بنجاح');
    }
}
