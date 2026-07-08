<?php

namespace App\Http\Controllers;

use App\Actions\StockReconciliation\CompleteStockReconciliationAction;
use App\Actions\StockReconciliation\DeleteStockReconciliationAction;
use App\Actions\StockReconciliation\StartStockReconciliationAction;
use App\Actions\StockReconciliation\UpdateStockReconciliationCountsAction;
use App\Http\Requests\StockReconciliation\StoreStockReconciliationRequest;
use App\Http\Requests\StockReconciliation\UpdateStockReconciliationCountsRequest;
use App\Http\Resources\StockReconciliation\StockReconciliationResource;
use App\Models\Branch;
use App\Models\StockReconciliation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class StockReconciliationController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', StockReconciliation::class);

        $user = auth()->user();
        $branchId = $user->branchId;

        $items = StockReconciliation::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['branch:id,name', 'initiatedBy:id,name'])
            ->withCount('lines')
            ->when($request->input('status') === 'in_progress', fn ($q) => $q->whereNull('completed_at'))
            ->when($request->input('status') === 'completed', fn ($q) => $q->whereNotNull('completed_at'))
            ->latest()
            ->paginate(12);

        return Inertia::render('inventory/stock-reconciliations/index', [
            'items' => StockReconciliationResource::collection($items),
            'branches' => $user->roleName->isSuperAdmin()
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'canManage' => Gate::allows('create', StockReconciliation::class),
            'filters' => [
                'status' => $request->input('status'),
            ],
        ]);
    }

    public function store(StoreStockReconciliationRequest $request, StartStockReconciliationAction $action): RedirectResponse
    {
        Gate::authorize('create', StockReconciliation::class);

        $reconciliation = $action->handle($request->validated());

        return to_route('inventory.stock-reconciliations.show', $reconciliation)
            ->with('success', 'تم بدء الجرد بنجاح');
    }

    public function show(StockReconciliation $stockReconciliation): Response
    {
        Gate::authorize('view', $stockReconciliation);

        $stockReconciliation->load(['branch:id,name', 'initiatedBy:id,name', 'lines.product'])->loadCount('lines');

        return Inertia::render('inventory/stock-reconciliations/show', [
            'reconciliation' => new StockReconciliationResource($stockReconciliation),
            'canManage' => Gate::allows('update', $stockReconciliation),
        ]);
    }

    public function updateCounts(
        UpdateStockReconciliationCountsRequest $request,
        StockReconciliation $stockReconciliation,
        UpdateStockReconciliationCountsAction $action,
    ): RedirectResponse {
        Gate::authorize('update', $stockReconciliation);

        $action->handle($stockReconciliation, $request->validated()['counts']);

        return to_route('inventory.stock-reconciliations.show', $stockReconciliation)
            ->with('success', 'تم حفظ الكميات المجرودة');
    }

    public function complete(StockReconciliation $stockReconciliation, CompleteStockReconciliationAction $action): RedirectResponse
    {
        Gate::authorize('complete', $stockReconciliation);

        $action->handle($stockReconciliation);

        return to_route('inventory.stock-reconciliations.show', $stockReconciliation)
            ->with('success', 'تم اعتماد الجرد وتسوية الفروقات');
    }

    public function destroy(StockReconciliation $stockReconciliation, DeleteStockReconciliationAction $action): RedirectResponse
    {
        Gate::authorize('delete', $stockReconciliation);

        $action->handle($stockReconciliation);

        return to_route('inventory.stock-reconciliations.index')
            ->with('success', 'تم حذف الجرد');
    }
}
