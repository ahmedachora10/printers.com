<?php

namespace App\Http\Controllers;

use App\Actions\PurchaseOrder\CancelPurchaseOrderAction;
use App\Actions\PurchaseOrder\CreatePurchaseOrderAction;
use App\Actions\PurchaseOrder\DeletePurchaseOrderAction;
use App\Actions\PurchaseOrder\MarkPurchaseOrderSentAction;
use App\Actions\PurchaseOrder\ReceivePurchaseOrderAction;
use App\Actions\PurchaseOrder\UpdatePurchaseOrderAction;
use App\Enums\PurchaseOrderStatusEnum;
use App\Http\Requests\PurchaseOrder\ReceivePurchaseOrderRequest;
use App\Http\Requests\PurchaseOrder\StorePurchaseOrderRequest;
use App\Http\Requests\PurchaseOrder\UpdatePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrder\PurchaseOrderResource;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        $branchId = auth()->user()->branchId ?? null;

        $items = PurchaseOrder::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['supplier'])
            ->withCount('lines')
            ->with('lines:id,po_id,subtotal')
            ->when($request->filled('search'), fn ($q) => $q->where('po_number', 'like', '%'.$request->input('search').'%'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', (int) $request->input('supplier_id')))
            ->latest()
            ->paginate(12);

        return Inertia::render('inventory/purchase-orders/index', [
            'items' => PurchaseOrderResource::collection($items),
            'suppliers' => $this->branchSuppliers($branchId),
            'products' => $this->branchProducts($branchId),
            'statuses' => $this->statusOptions(),
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'supplier_id' => $request->input('supplier_id'),
            ],
        ]);
    }

    public function store(StorePurchaseOrderRequest $request, CreatePurchaseOrderAction $action): RedirectResponse
    {
        Gate::authorize('create', PurchaseOrder::class);

        $po = $action->handle($request->validated());

        return to_route('inventory.purchase-orders.show', $po)->with('success', 'تم إنشاء أمر الشراء بنجاح');
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        Gate::authorize('view', $purchaseOrder);

        $purchaseOrder->load(['supplier', 'orderedBy', 'lines.product'])->loadCount('lines');

        return Inertia::render('inventory/purchase-orders/show', [
            'purchaseOrder' => new PurchaseOrderResource($purchaseOrder),
        ]);
    }

    public function update(UpdatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, UpdatePurchaseOrderAction $action): RedirectResponse
    {
        Gate::authorize('update', $purchaseOrder);

        $action->handle($purchaseOrder, $request->validated());

        return to_route('inventory.purchase-orders.show', $purchaseOrder)->with('success', 'تم تحديث أمر الشراء بنجاح');
    }

    public function destroy(PurchaseOrder $purchaseOrder, DeletePurchaseOrderAction $action): RedirectResponse
    {
        Gate::authorize('delete', $purchaseOrder);

        $action->handle($purchaseOrder);

        return to_route('inventory.purchase-orders.index')->with('success', 'تم حذف أمر الشراء بنجاح');
    }

    public function markSent(PurchaseOrder $purchaseOrder, MarkPurchaseOrderSentAction $action): RedirectResponse
    {
        Gate::authorize('update', $purchaseOrder);

        $action->handle($purchaseOrder);

        return to_route('inventory.purchase-orders.show', $purchaseOrder)->with('success', 'تم إرسال أمر الشراء');
    }

    public function receive(ReceivePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, ReceivePurchaseOrderAction $action): RedirectResponse
    {
        Gate::authorize('receive', $purchaseOrder);

        $action->handle($purchaseOrder, $request->validated()['receipts']);

        return to_route('inventory.purchase-orders.show', $purchaseOrder)->with('success', 'تم تسجيل الاستلام بنجاح');
    }

    public function cancel(PurchaseOrder $purchaseOrder, CancelPurchaseOrderAction $action): RedirectResponse
    {
        Gate::authorize('update', $purchaseOrder);

        $action->handle($purchaseOrder);

        return to_route('inventory.purchase-orders.show', $purchaseOrder)->with('success', 'تم إلغاء أمر الشراء');
    }

    /** @return Collection<int, array<string, mixed>> */
    private function branchSuppliers(?int $branchId)
    {
        return Supplier::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function branchProducts(?int $branchId)
    {
        return Product::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'cost_price'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'costPrice' => (float) $product->cost_price,
            ]);
    }

    /** @return array<int, array{value: string, label: string}> */
    private function statusOptions(): array
    {
        return array_map(
            fn (PurchaseOrderStatusEnum $status) => ['value' => $status->value, 'label' => $status->label()],
            PurchaseOrderStatusEnum::cases(),
        );
    }
}
