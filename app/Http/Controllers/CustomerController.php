<?php

namespace App\Http\Controllers;

use App\Actions\Customer\CreateCustomerAction;
use App\Actions\Customer\DeleteCustomerAction;
use App\Actions\Customer\MergeCustomersAction;
use App\Actions\Customer\OverrideCustomerTierAction;
use App\Actions\Customer\SearchPosCustomersAction;
use App\Actions\Customer\UpdateCustomerAction;
use App\Exports\CustomersExport;
use App\Http\Requests\Customer\MergeCustomersRequest;
use App\Http\Requests\Customer\OverrideCustomerTierRequest;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Http\Resources\Branch\BranchResource;
use App\Http\Resources\Customer\CustomerResource;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use stdClass;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $isSuperAdmin = Auth::user()->roleName->isSuperAdmin();
        $branchId = $isSuperAdmin ? null : Auth::user()->branchId;

        $query = Customer::query()
            ->with('branch:id,name')
            ->when(! $isSuperAdmin, fn ($q) => $q->where('branch_id', $branchId))
            // Super-admins see every branch by default, and may narrow to one.
            ->when($isSuperAdmin && $request->filled('branch_id'),
                fn ($q) => $q->where('branch_id', (int) $request->input('branch_id')))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('full_name', 'like', '%'.$request->input('search').'%')
                    ->orWhere('phone', 'like', '%'.$request->input('search').'%');
            }))
            ->when($request->filled('tier'), fn ($q) => $q->where('tier', $request->input('tier')))
            ->when($request->filled('type'), fn ($q) => $q->where('customer_type', $request->input('type')))
            ->when($request->filled('agent_id'), fn ($q) => $q->where('agent_id', (int) $request->input('agent_id')))
            ->when($request->boolean('has_outstanding'), fn ($q) => $q->whereExists(function ($sub) {
                foreach (['service_invoices', 'product_invoices'] as $table) {
                    if (Schema::hasTable($table)) {
                        $sub->selectRaw('1')->from($table)
                            ->whereColumn("{$table}.customer_id", 'customers.id')
                            ->where("{$table}.status", 'due')
                            ->whereNull("{$table}.deleted_at");

                        return;
                    }
                }
            }))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $ids = $query->pluck('id');
        $stats = $this->loadPageStats($ids);

        // An agent may work with several branches, so the pickable list follows
        // the branch links rather than the primary branch_id column.
        $agents = Agent::select('id', 'name')
            ->when(! $isSuperAdmin, fn ($q) => $q->forBranch($branchId))
            ->get();

        $branches = $isSuperAdmin ? Branch::select('id', 'name')->get() : [];

        return Inertia::render('customers/index', [
            'items' => CustomerResource::collection($query),
            'stats' => $stats,
            'agents' => $agents,
            'branches' => BranchResource::collection($branches),
            'isSuperAdmin' => $isSuperAdmin,
            'filters' => $request->only(['search', 'tier', 'type', 'agent_id', 'has_outstanding', 'branch_id']),
        ]);
    }

    /**
     * Async customer lookup for the POS pickers — scoped to the cashier's branch,
     * capped at 30 rows so 10k+ customers never ship to the browser at once.
     */
    public function posSearch(Request $request, SearchPosCustomersAction $action): JsonResponse
    {
        $customers = $action->handle(
            Auth::user()->branchId,
            (string) $request->query('q', ''),
        );

        return response()->json(['data' => $customers]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Customer::class);

        return Inertia::render('customers/create');
    }

    public function store(StoreCustomerRequest $request, CreateCustomerAction $action): RedirectResponse
    {
        Gate::authorize('create', Customer::class);

        $isSuperAdmin = Auth::user()->roleName->isSuperAdmin();
        $branchId = $isSuperAdmin
            ? (int) $request->input('branch_id')
            : Auth::user()->branchId;

        $action->handle($request->validated(), $branchId);

        return back(fallback: route('customers.index'))->with('success', 'تم إنشاء العميل بنجاح');
    }

    public function show(Customer $customer): Response
    {
        Gate::authorize('view', $customer);

        $customer->load(['branch', 'agent']);

        $customers = Customer::select('id', 'full_name')
            ->when(! Auth::user()->branchId, function ($query) {
                return $query->where('branch_id', Auth::user()->branchId);
            })
            ->where('id', '<>', $customer->id)
            ->get();

        return Inertia::render('customers/show', [
            'customer' => new CustomerResource($customer),
            'financialSummary' => $this->computeFinancialSummary($customer),
            'loyaltyHistory' => $this->loadLoyaltyHistory($customer),
            'invoiceHistory' => $this->loadInvoiceHistory($customer),
            'customers' => CustomerResource::collection($customers),
            'canOverrideTier' => Gate::allows('overrideTier', $customer),
        ]);
    }

    public function edit(Customer $customer): Response
    {
        Gate::authorize('update', $customer);

        return Inertia::render('customers/edit', [
            'customer' => new CustomerResource($customer),
        ]);
    }

    public function update(
        UpdateCustomerRequest $request,
        Customer $customer,
        UpdateCustomerAction $action,
    ): RedirectResponse {
        Gate::authorize('update', $customer);

        $action->handle($customer, $request->validated());

        return to_route('customers.show', $customer)->with('success', 'تم تحديث بيانات العميل بنجاح');
    }

    public function destroy(Customer $customer, DeleteCustomerAction $action): RedirectResponse
    {
        Gate::authorize('delete', $customer);

        $action->handle($customer);

        return back(fallback: route('customers.index'))->with('success', 'تم حذف العميل بنجاح');
    }

    public function merge(
        MergeCustomersRequest $request,
        Customer $customer,
        MergeCustomersAction $action,
    ): RedirectResponse {
        Gate::authorize('merge', $customer);

        $secondary = Customer::findOrFail($request->validated('secondary_customer_id'));

        Gate::authorize('merge', $secondary);

        $action->handle($customer, $secondary);

        return to_route('customers.show', $customer)->with('success', 'تم دمج العميلين بنجاح');
    }

    public function outstandingBalance(): Response
    {
        Gate::authorize('viewOutstanding', Customer::class);

        $branchId = Auth::user()->branchId;

        $report = $this->buildOutstandingReport($branchId);

        return Inertia::render('customers/outstanding-balance', [
            'report' => $report,
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        Gate::authorize('viewAny', Customer::class);

        $isSuperAdmin = Auth::user()->roleName->isSuperAdmin();
        $branchId = $isSuperAdmin ? null : Auth::user()->branchId;

        // Only the cross-branch role gets a branch column; everyone else exports
        // a single branch, where the column would repeat the same value.
        return Excel::download(new CustomersExport($branchId, $isSuperAdmin), 'customers.xlsx');
    }

    public function toggleStatus(Customer $customer, UpdateCustomerAction $action): RedirectResponse
    {
        Gate::authorize('update', $customer);

        $action->handle($customer, ['is_active' => ! $customer->is_active]);

        return back(fallback: route('customers.index'))->with('success', 'تم تحديث حالة العميل بنجاح');
    }

    /**
     * تعديل مستوى الولاء يدوياً — ومعه تصحيحٌ اختياري للإنفاق التراكمي، وإلا
     * أعاد أوّلُ اكتسابٍ ترقيةَ العميل إلى مستواه السابق.
     */
    public function overrideTier(
        OverrideCustomerTierRequest $request,
        Customer $customer,
        OverrideCustomerTierAction $action,
    ): RedirectResponse {
        Gate::authorize('overrideTier', $customer);

        $action->handle($customer, $request->validated(), $request->user());

        return back(fallback: route('customers.show', $customer))
            ->with('success', 'تم تحديث مستوى الولاء بنجاح');
    }

    /**
     * @param  Collection<int,int>  $ids
     * @return array<int|string, array<string, mixed>>
     */
    private function loadPageStats(Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        $stats = [];

        foreach (['service_invoices', 'product_invoices'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)
                ->whereIn('customer_id', $ids)
                ->whereNull('deleted_at')
                ->groupBy('customer_id')
                ->select(
                    'customer_id',
                    DB::raw('COUNT(*) as cnt'),
                    DB::raw('MAX(created_at) as last_at'),
                    DB::raw('SUM(CASE WHEN status = \'due\' THEN total_amount ELSE 0 END) as outstanding'),
                )
                ->get();

            foreach ($rows as $row) {
                $id = $row->customer_id;
                $stats[$id] ??= ['invoiceCount' => 0, 'outstanding' => 0, 'lastVisit' => null];
                $stats[$id]['invoiceCount'] += (int) $row->cnt;
                $stats[$id]['outstanding'] += (float) $row->outstanding;

                if ($row->last_at !== null) {
                    $current = $stats[$id]['lastVisit'];
                    $stats[$id]['lastVisit'] = $current === null || $row->last_at > $current
                        ? $row->last_at
                        : $current;
                }
            }
        }

        return $stats;
    }

    /** @return array<string, mixed> */
    private function computeFinancialSummary(Customer $customer): array
    {
        $totals = ['count' => 0, 'total' => 0.0, 'paid' => 0.0, 'due' => 0.0];

        foreach (['service_invoices', 'product_invoices'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $row = DB::table($table)
                ->where('customer_id', $customer->id)
                ->whereNull('deleted_at')
                ->selectRaw(
                    'COUNT(*) as cnt,
                    COALESCE(SUM(total_amount), 0) as total,
                    COALESCE(SUM(CASE WHEN status = \'paid\' THEN total_amount ELSE 0 END), 0) as paid,
                    COALESCE(SUM(CASE WHEN status = \'due\' THEN total_amount ELSE 0 END), 0) as due'
                )
                ->first();

            if ($row !== null) {
                $totals['count'] += (int) $row->cnt;
                $totals['total'] += (float) $row->total;
                $totals['paid'] += (float) $row->paid;
                $totals['due'] += (float) $row->due;
            }
        }

        return [
            'invoiceCount' => $totals['count'],
            'totalBilled' => $totals['total'],
            'totalPaid' => $totals['paid'],
            'totalOutstanding' => $totals['due'],
            'creditLimit' => $customer->credit_limit,
            'availableCredit' => $customer->credit_limit !== null
                ? max(0, (float) $customer->credit_limit - $totals['due'])
                : null,
        ];
    }

    /** @return array<int, stdClass> */
    private function loadLoyaltyHistory(Customer $customer): array
    {
        if (! Schema::hasTable('loyalty_transactions')) {
            return [];
        }

        return DB::table('loyalty_transactions')
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get(['id', 'type', 'points', 'balance_after', 'notes', 'created_at'])
            ->toArray();
    }

    /** @return array<int, array<string, mixed>> */
    private function loadInvoiceHistory(Customer $customer): array
    {
        $invoices = [];

        foreach (['service_invoices', 'product_invoices'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $type = $table === 'service_invoices' ? 'service' : 'product';
            $rows = DB::table($table)
                ->where('customer_id', $customer->id)
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->limit(100)
                ->get(['id', 'invoice_number', 'total_amount', 'status', 'created_at'])
                ->map(fn ($r) => array_merge((array) $r, ['type' => $type]))
                ->toArray();

            $invoices = array_merge($invoices, $rows);
        }

        usort($invoices, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return array_slice($invoices, 0, 100);
    }

    /** @return array<int, array<string, mixed>> */
    private function buildOutstandingReport(?int $branchId): array
    {
        $customers = Customer::query()
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['id', 'full_name', 'phone', 'company_name', 'customer_type']);

        if ($customers->isEmpty()) {
            return [];
        }

        $ids = $customers->pluck('id');
        $totals = [];
        $oldestDue = [];

        foreach (['service_invoices', 'product_invoices'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)
                ->whereIn('customer_id', $ids)
                ->where('status', 'due')
                ->whereNull('deleted_at')
                ->groupBy('customer_id')
                ->select(
                    'customer_id',
                    DB::raw('SUM(total_amount) as due_amount'),
                    DB::raw('MIN(created_at) as oldest_due'),
                )
                ->get();

            foreach ($rows as $row) {
                $cid = $row->customer_id;
                $totals[$cid] = ($totals[$cid] ?? 0) + (float) $row->due_amount;
                $oldestDue[$cid] = isset($oldestDue[$cid])
                    ? min($oldestDue[$cid], $row->oldest_due)
                    : $row->oldest_due;
            }
        }

        return $customers
            ->filter(fn ($c) => isset($totals[$c->id]))
            ->map(fn ($c) => [
                'id' => $c->id,
                'fullName' => $c->full_name,
                'phone' => $c->phone,
                'companyName' => $c->company_name,
                'totalDue' => $totals[$c->id],
                'oldestDue' => $oldestDue[$c->id],
            ])
            ->sortByDesc('totalDue')
            ->values()
            ->toArray();
    }
}
