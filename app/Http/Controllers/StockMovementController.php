<?php

namespace App\Http\Controllers;

use App\Actions\StockMovement\RecordStockMovementAction;
use App\Enums\StockMovementTypeEnum;
use App\Http\Requests\StockMovement\StoreStockMovementRequest;
use App\Http\Resources\StockMovement\StockMovementResource;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class StockMovementController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', StockMovement::class);

        $branchId = auth()->user()->branchId ?? null;

        $items = StockMovement::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['product:id,name,sku', 'creator:id,name'])
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', (int) $request->input('product_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('created_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('created_at', '<=', $request->input('to')))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $products = Product::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderBy('name')
            ->get(['id', 'name']);

        $types = array_map(
            fn (StockMovementTypeEnum $type) => ['value' => $type->value, 'label' => $type->label()],
            StockMovementTypeEnum::cases(),
        );

        return Inertia::render('inventory/stock-movements/index', [
            'items' => StockMovementResource::collection($items),
            'products' => $products,
            'types' => $types,
            'filters' => [
                'product_id' => $request->input('product_id'),
                'type' => $request->input('type'),
                'from' => $request->input('from'),
                'to' => $request->input('to'),
            ],
        ]);
    }

    public function store(StoreStockMovementRequest $request, RecordStockMovementAction $action): RedirectResponse
    {
        Gate::authorize('create', StockMovement::class);

        $product = Product::findOrFail($request->integer('product_id'));

        Gate::authorize('update', $product);

        $action->handle(
            $product,
            StockMovementTypeEnum::from($request->input('type')),
            $request->integer('qty'),
            [
                'unit_cost' => $request->input('unit_cost'),
                'notes' => $request->input('notes'),
            ],
        );

        return to_route('inventory.products.index')->with('success', 'تم تسجيل حركة المخزون بنجاح');
    }
}
