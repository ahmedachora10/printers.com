<?php

namespace App\Http\Controllers;

use App\Actions\Supplier\CreateSupplierAction;
use App\Actions\Supplier\DeleteSupplierAction;
use App\Actions\Supplier\UpdateSupplierAction;
use App\Http\Requests\Supplier\StoreSupplierRequest;
use App\Http\Requests\Supplier\UpdateSupplierRequest;
use App\Http\Resources\Supplier\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Supplier::class);

        $branchId = auth()->user()->branchId ?? null;

        $items = Supplier::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->withCount('purchaseOrders')
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('name', 'like', '%'.$request->input('search').'%')
                    ->orWhere('phone', 'like', '%'.$request->input('search').'%')
                    ->orWhere('email', 'like', '%'.$request->input('search').'%');
            }))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', (bool) $request->input('status')))
            ->orderBy('name')
            ->paginate(12);

        return Inertia::render('inventory/suppliers/index', [
            'items' => SupplierResource::collection($items),
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
            ],
        ]);
    }

    public function store(StoreSupplierRequest $request, CreateSupplierAction $action): RedirectResponse
    {
        Gate::authorize('create', Supplier::class);

        $action->handle($request->validated());

        return to_route('inventory.suppliers.index')->with('success', 'تم إضافة المورد بنجاح');
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier, UpdateSupplierAction $action): RedirectResponse
    {
        Gate::authorize('update', $supplier);

        $action->handle($supplier, $request->validated());

        return to_route('inventory.suppliers.index')->with('success', 'تم تحديث المورد بنجاح');
    }

    public function destroy(Supplier $supplier, DeleteSupplierAction $action): RedirectResponse
    {
        Gate::authorize('delete', $supplier);

        $action->handle($supplier);

        return to_route('inventory.suppliers.index')->with('success', 'تم حذف المورد بنجاح');
    }

    public function toggleStatus(Supplier $supplier, UpdateSupplierAction $action): RedirectResponse
    {
        Gate::authorize('update', $supplier);

        $action->handle($supplier, ['is_active' => ! $supplier->is_active]);

        return to_route('inventory.suppliers.index')->with('success', 'تم تحديث حالة المورد بنجاح');
    }
}
