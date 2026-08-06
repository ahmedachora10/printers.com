<?php

namespace App\Http\Controllers;

use App\Actions\PurchaseRequest\ApprovePurchaseRequestAction;
use App\Actions\PurchaseRequest\ConvertToPurchaseOrderAction;
use App\Actions\PurchaseRequest\CreatePurchaseRequestAction;
use App\Actions\PurchaseRequest\RejectPurchaseRequestAction;
use App\Enums\PurchaseRequestStatusEnum;
use App\Enums\Roles;
use App\Http\Requests\PurchaseRequest\ConvertPurchaseRequestRequest;
use App\Http\Requests\PurchaseRequest\RejectPurchaseRequestRequest;
use App\Http\Requests\PurchaseRequest\StorePurchaseRequestRequest;
use App\Http\Resources\PurchaseRequest\PurchaseRequestResource;
use App\Models\Branch;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Notifications\PurchaseRequestDecidedNotification;
use App\Notifications\PurchaseRequestSubmittedNotification;
use App\Support\BranchNotifiables;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseRequestController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PurchaseRequest::class);

        $items = PurchaseRequest::query()
            ->visibleTo(Auth::user())
            ->with(['branch:id,name', 'requestedBy:id,name', 'decidedBy:id,name', 'purchaseOrder:id,po_number', 'lines.product:id,sku'])
            ->withCount('lines')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), fn ($q) => $q->whereHas(
                'lines',
                fn ($lines) => $lines->where('item_name', 'like', '%'.$request->input('search').'%')
            ))
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('inventory/purchase-requests/index', [
            'items' => PurchaseRequestResource::collection($items),
            'products' => $this->productOptions(),
            'suppliers' => $this->supplierOptions(),
            'branches' => $this->branchOptions(),
            'statuses' => $this->statusOptions(),
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
            ],
        ]);
    }

    public function store(StorePurchaseRequestRequest $request, CreatePurchaseRequestAction $action): RedirectResponse
    {
        Gate::authorize('create', PurchaseRequest::class);

        $purchaseRequest = $action->handle($request->validated());

        Notification::send(
            BranchNotifiables::forBranch($purchaseRequest->branch_id, [Roles::BRANCH_ADMIN->value, Roles::ACCOUNTANT->value])
                ->reject(fn ($user) => $user->id === Auth::id()),
            new PurchaseRequestSubmittedNotification(
                $purchaseRequest->id,
                Auth::user()->name,
                $purchaseRequest->lines()->count(),
            ),
        );

        return to_route('purchase-requests.index')->with('success', 'تم إرسال طلب الشراء بنجاح');
    }

    public function approve(PurchaseRequest $purchaseRequest, ApprovePurchaseRequestAction $action): RedirectResponse
    {
        Gate::authorize('decide', $purchaseRequest);

        $action->handle($purchaseRequest);

        $this->notifyRequester($purchaseRequest);

        return back(fallback: route('purchase-requests.index'))->with('success', 'تم اعتماد طلب الشراء');
    }

    public function reject(RejectPurchaseRequestRequest $request, PurchaseRequest $purchaseRequest, RejectPurchaseRequestAction $action): RedirectResponse
    {
        Gate::authorize('decide', $purchaseRequest);

        $action->handle($purchaseRequest, $request->validated()['decision_reason']);

        $this->notifyRequester($purchaseRequest);

        return back(fallback: route('purchase-requests.index'))->with('success', 'تم رفض طلب الشراء');
    }

    public function convert(ConvertPurchaseRequestRequest $request, PurchaseRequest $purchaseRequest, ConvertToPurchaseOrderAction $action): RedirectResponse
    {
        Gate::authorize('convert', $purchaseRequest);

        $po = $action->handle($purchaseRequest, $request->validated());

        return to_route('inventory.purchase-orders.show', $po)
            ->with('success', "تم تحويل الطلب إلى أمر الشراء {$po->po_number}");
    }

    private function notifyRequester(PurchaseRequest $purchaseRequest): void
    {
        $requester = $purchaseRequest->requestedBy;

        if (! $requester || $requester->id === Auth::id()) {
            return;
        }

        $requester->notify(new PurchaseRequestDecidedNotification(
            $purchaseRequest->id,
            $purchaseRequest->status,
            $purchaseRequest->decision_reason,
        ));
    }

    /**
     * Products/suppliers carry their branch so the page can narrow the option
     * lists to the branch a super-admin is filing (or converting) for.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function productOptions(): Collection
    {
        $branchId = Auth::user()->branchId;

        return Product::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'branch_id', 'name', 'sku', 'cost_price'])
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'branchId' => $product->branch_id,
                'name' => $product->name,
                'sku' => $product->sku,
                'costPrice' => (float) $product->cost_price,
            ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function supplierOptions(): Collection
    {
        $branchId = Auth::user()->branchId;

        return Supplier::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'branch_id', 'name'])
            ->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'branchId' => $supplier->branch_id,
                'name' => $supplier->name,
            ]);
    }

    /**
     * Only a super-admin picks a branch; everybody else files against their own.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function branchOptions(): Collection
    {
        if (! Auth::user()->roleName?->isSuperAdmin()) {
            return collect();
        }

        return Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /** @return array<int, array{value: string, label: string}> */
    private function statusOptions(): array
    {
        return array_map(
            fn (PurchaseRequestStatusEnum $status) => ['value' => $status->value, 'label' => $status->label()],
            PurchaseRequestStatusEnum::cases(),
        );
    }
}
