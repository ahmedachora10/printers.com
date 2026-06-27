<?php

namespace App\Http\Controllers;

use App\Actions\Product\CreateProductAction;
use App\Actions\Product\DeleteProductAction;
use App\Actions\Product\UpdateProductAction;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\Product\ProductResource;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Product::class);

        $branchId = auth()->user()->branchId ?? null;

        $items = Product::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['category', 'unit'])
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('name', 'like', '%' . $request->input('search') . '%')
                  ->orWhere('sku', 'like', '%' . $request->input('search') . '%');
            }))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', (int) $request->input('category_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', (bool) $request->input('status')))
            ->orderBy('name')
            ->paginate(12);

        $lowStockCount = Product::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereColumn('current_stock', '<=', 'min_stock_level')
            ->where('is_active', true)
            ->count();

        $categories = ProductCategory::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        $units = ProductUnit::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('inventory/products/index', [
            'items'         => ProductResource::collection($items),
            'lowStockCount' => $lowStockCount,
            'categories'    => $categories,
            'units'         => $units,
            'filters'       => [
                'search'      => $request->input('search'),
                'category_id' => $request->input('category_id'),
                'status'      => $request->input('status'),
            ],
        ]);
    }

    public function store(StoreProductRequest $request, CreateProductAction $action): RedirectResponse
    {
        Gate::authorize('create', Product::class);

        $action->handle($request->validated());

        return back(fallback: route('inventory.products.index'))->with('success', 'تم إضافة المنتج بنجاح');
    }

    public function update(UpdateProductRequest $request, Product $product, UpdateProductAction $action): RedirectResponse
    {
        Gate::authorize('update', $product);

        $action->handle($product, $request->validated());

        return back(fallback: route('inventory.products.index'))->with('success', 'تم تحديث المنتج بنجاح');
    }

    public function destroy(Product $product, DeleteProductAction $action): RedirectResponse
    {
        Gate::authorize('delete', $product);

        $action->handle($product);

        return back(fallback: route('inventory.products.index'))->with('success', 'تم حذف المنتج بنجاح');
    }

    public function toggleStatus(Product $product, UpdateProductAction $action): RedirectResponse
    {
        Gate::authorize('update', $product);

        $action->handle($product, ['is_active' => ! $product->is_active]);

        return back(fallback: route('inventory.products.index'))->with('success', 'تم تحديث حالة المنتج بنجاح');
    }
}
