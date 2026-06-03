<?php

namespace App\Http\Controllers;

use App\Actions\Invoice\GenerateZatcaQrAction;
use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use App\Http\Resources\Invoice\InvoiceListResource;
use App\Http\Resources\Invoice\InvoiceResource;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        $user = Auth::user();
        $isSuperAdmin = $user->roleName->isSuperAdmin();
        $branchId = $isSuperAdmin ? null : $user->branchId;

        $allowedTypes = $this->allowedTypesFor();

        // Honour an explicit type filter, but never beyond what the role allows.
        $requestedType = $request->input('type');
        $types = $requestedType && in_array($requestedType, InvoiceTypeEnum::all(), true)
            ? array_values(array_filter($allowedTypes, fn ($t) => $t->value === $requestedType))
            : $allowedTypes;

        $subQueries = array_map(
            fn (InvoiceTypeEnum $type) => $this->buildTypeQuery($type, $request, $isSuperAdmin, $branchId),
            $types,
        );

        if (empty($subQueries)) {
            $union = DB::table('product_invoices')->whereRaw('1 = 0')
                ->selectRaw('null as id, null as invoice_number, null as total_amount, null as status, null as created_at, null as type, null as customer_name');
        } else {
            $union = array_shift($subQueries);
            foreach ($subQueries as $sub) {
                $union->unionAll($sub);
            }
        }

        $invoices = DB::query()
            ->fromSub($union, 'invoices')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('invoices/index', [
            'items' => InvoiceListResource::collection($invoices),
            'isSuperAdmin' => $isSuperAdmin,
            'availableTypes' => array_map(
                fn (InvoiceTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                $allowedTypes,
            ),
            'filters' => $request->only(['search', 'type', 'status', 'date_from', 'date_to']),
        ]);
    }

    public function show(string $type, int $id): Response
    {
        $invoice = $this->resolveInvoice($type, $id);
        Gate::authorize('view', $invoice);

        $invoice->load([
            'lines',
            'customer:id,full_name,phone',
            'paymentMethod:id,name',
            'branch',
            'refunds' => fn ($q) => $q->with('user:id,name')->latest(),
        ]);

        return Inertia::render('invoices/show', [
            'invoice' => new InvoiceResource($invoice),
        ]);
    }

    public function print(string $type, int $id, Request $request, GenerateZatcaQrAction $qrAction): Response
    {
        $invoice = $this->resolveInvoice($type, $id);
        Gate::authorize('view', $invoice);

        $invoice->load(['lines', 'customer:id,full_name,phone', 'paymentMethod:id,name', 'branch']);

        $format = $request->input('format') === 'thermal' ? 'thermal' : 'a4';

        return Inertia::render('invoices/print', [
            'invoice' => new InvoiceResource($invoice),
            'format' => $format,
            'zatcaQr' => $qrAction->handle($invoice),
        ]);
    }

    /**
     * Resolve {type}/{id} to the concrete invoice model, or 404.
     */
    private function resolveInvoice(string $type, int $id)
    {
        $enum = InvoiceTypeEnum::tryFrom($type);
        abort_if($enum === null, 404);

        return $enum->modelClass()::findOrFail($id);
    }

    /**
     * Invoice types the current user's role is permitted to browse,
     * mirroring ProductInvoicePolicy / ServiceInvoicePolicy.
     *
     * @return list<InvoiceTypeEnum>
     */
    private function allowedTypesFor(): array
    {
        $role = Auth::user()->roleName;

        $types = [];
        if ($role->isSuperAdmin() || $role->isBranchAdmin() || $role->isAccountant()) {
            $types[] = InvoiceTypeEnum::PRODUCT;
        }
        if ($role->isSuperAdmin() || $role->isBranchAdmin() || $role->isEmployee()) {
            $types[] = InvoiceTypeEnum::SERVICE;
        }

        return $types;
    }

    /**
     * Build a normalized sub-query for one invoice type, with filters applied.
     */
    private function buildTypeQuery(InvoiceTypeEnum $type, Request $request, bool $isSuperAdmin, ?int $branchId): Builder
    {
        $table = $type->table();

        return DB::table($table)
            ->leftJoin('customers', 'customers.id', '=', "{$table}.customer_id")
            ->whereNull("{$table}.deleted_at")
            ->when(! $isSuperAdmin, fn ($q) => $q->where("{$table}.branch_id", $branchId))
            ->when($request->filled('status') && in_array($request->input('status'), InvoiceStatusEnum::all(), true),
                fn ($q) => $q->where("{$table}.status", $request->input('status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate("{$table}.created_at", '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate("{$table}.created_at", '<=', $request->input('date_to')))
            ->when($request->filled('search'), fn ($q) => $q->where("{$table}.invoice_number", 'like', '%'.$request->input('search').'%'))
            ->select([
                "{$table}.id",
                "{$table}.invoice_number",
                "{$table}.total_amount",
                "{$table}.status",
                "{$table}.created_at",
                DB::raw("'{$type->value}' as type"),
                'customers.full_name as customer_name',
            ]);
    }
}
