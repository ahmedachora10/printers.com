<?php

namespace App\Http\Controllers;

use App\Actions\Customer\CreateCustomerAction;
use App\Actions\Customer\DeleteCustomerAction;
use App\Actions\Customer\MergeCustomersAction;
use App\Actions\Customer\OverrideCustomerTierAction;
use App\Actions\Customer\SearchPosCustomersAction;
use App\Actions\Customer\UpdateCustomerAction;
use App\Exports\CustomersExport;
use App\Http\Controllers\Concerns\BuildsPagedProps;
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
    use BuildsPagedProps;

    private const HISTORY_PER_PAGE = 10;

    /** فلاتر الشاشة التي يتبعها التصدير أيضاً — انظر Customer::scopeFilteredBy(). */
    private const LIST_FILTERS = ['search', 'tier', 'type', 'agent_id', 'has_outstanding'];

    /**
     * ذاكرةُ وجودِ الجداول لهذا الطلب. الفحص يضرب sqlite_master (أو
     * information_schema) في كل نداء، وصفحةُ العميل الواحدة تسأل عن جدولَي
     * الفواتير مرتين — مرةً للملخّص المالي ومرةً للسجلّ — فيُحفظ الجواب.
     *
     * @var array<string, bool>
     */
    private array $tableExists = [];

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $isSuperAdmin = Auth::user()->roleName->isSuperAdmin();
        // Super-admins see every branch by default, and may narrow to one.
        $branchId = $this->listBranch($request);

        $query = Customer::query()
            ->with($this->accountsIn($branchId))
            ->when($branchId, fn ($q) => $q->inBranch($branchId))
            ->filteredBy($request->only(self::LIST_FILTERS), $branchId)
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $ids = $query->pluck('id');
        $stats = $this->loadPageStats($ids, $branchId);

        // An agent may work with several branches, so the pickable list follows
        // the branch links rather than the primary branch_id column.
        $agents = Agent::select('id', 'name')
            ->when(! $isSuperAdmin, fn ($q) => $q->forBranch(Auth::user()->branchId))
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

    public function show(Request $request, Customer $customer): Response
    {
        Gate::authorize('view', $customer);

        // معاملات كل فرعٍ لفرعه: موظف الفرع يرى حسابه وفواتيره هناك وحدها.
        $branchId = $this->userBranch();
        $customer->load($this->accountsIn($branchId));

        // مرشّحو الدمج من عملاء فروعه (فرع المستخدم وحده لغير السوبر أدمن).
        $customers = Customer::select('id', 'full_name')
            ->whereHas('accounts', fn ($q) => $q->whereIn('branch_id', $customer->accounts->pluck('branch_id')))
            ->where('id', '<>', $customer->id)
            ->get();

        return Inertia::render('customers/show', [
            'customer' => new CustomerResource($customer),
            'financialSummary' => $this->computeFinancialSummary($customer, $branchId),
            'loyaltyHistory' => $this->loadLoyaltyHistory($request, $customer, $branchId),
            'invoiceHistory' => $this->loadInvoiceHistory($request, $customer, $branchId),
            'customers' => CustomerResource::collection($customers),
            'canOverrideTier' => Gate::allows('overrideTier', $customer),
        ]);
    }

    public function edit(Customer $customer): Response
    {
        Gate::authorize('update', $customer);

        return Inertia::render('customers/edit', [
            'customer' => new CustomerResource($customer->load($this->accountsIn($this->userBranch()))),
        ]);
    }

    public function update(
        UpdateCustomerRequest $request,
        Customer $customer,
        UpdateCustomerAction $action,
    ): RedirectResponse {
        Gate::authorize('update', $customer);

        $action->handle($customer, $request->validated(), $this->editedBranch($request, $customer));

        return to_route('customers.show', $customer)->with('success', 'تم تحديث بيانات العميل بنجاح');
    }

    /** مدير الفرع يفصله عن فرعه؛ والسوبر أدمن يحذفه من كل الفروع. */
    public function destroy(Customer $customer, DeleteCustomerAction $action): RedirectResponse
    {
        Gate::authorize('delete', $customer);

        $action->handle($customer, $this->userBranch());

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

        // The export follows the screen: the super-admin's branch filter narrows
        // it just as it narrows the list. Only the cross-branch role gets a
        // branch column; everyone else exports a single branch.
        return Excel::download(
            new CustomersExport($this->listBranch($request), Auth::user()->roleName->isSuperAdmin(), $request->only(self::LIST_FILTERS)),
            'customers.xlsx',
        );
    }

    /** النشاط لكل فرع: موظف الفرع يبدّل حسابه، والسوبر أدمن كل حساباته معاً. */
    public function toggleStatus(Customer $customer): RedirectResponse
    {
        Gate::authorize('update', $customer);

        $accounts = $customer->load($this->accountsIn($this->userBranch()))->accounts;
        $active = ! $accounts->contains('is_active', true);

        DB::transaction(fn () => $accounts->each->update(['is_active' => $active]));

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

        $account = $customer->accountFor($this->editedBranch($request, $customer));
        abort_if($account === null, 422, 'اختر الفرع الذي يُعدَّل فيه المستوى.');

        $action->handle($account, $request->validated(), $request->user());

        return back(fallback: route('customers.show', $customer))
            ->with('success', 'تم تحديث مستوى الولاء بنجاح');
    }

    /** فرع المستخدم — أو null للسوبر أدمن: كل الفروع. */
    private function userBranch(): ?int
    {
        return Auth::user()->roleName->isSuperAdmin() ? null : Auth::user()->branchId;
    }

    /** فرع قائمة العملاء وتصديرها: فرع المستخدم، أو ما اختاره السوبر أدمن من الفلتر. */
    private function listBranch(Request $request): ?int
    {
        return $this->userBranch() ?? ($request->filled('branch_id') ? (int) $request->input('branch_id') : null);
    }

    /**
     * الفرع الذي تُعدَّل حقوله (الائتمان، المندوب، الملاحظات، المستوى): فرع
     * المستخدم، أو ما اختاره السوبر أدمن، أو فرع العميل الوحيد. ولعميل عدّة
     * فروع بلا فرعٍ مختار: null — تُعدَّل الهوية وحدها ولا يُكتب حسابٌ بقيمٍ لم تُعرض.
     */
    private function editedBranch(Request $request, Customer $customer): ?int
    {
        $branchId = $this->userBranch() ?: $request->integer('branch_id') ?: null;
        $accounts = $customer->accounts()->pluck('branch_id');

        $branchId ??= $accounts->count() === 1 ? $accounts->first() : null;

        abort_if($branchId !== null && ! $accounts->contains($branchId), 422, 'العميل غير مرتبط بهذا الفرع.');

        return $branchId;
    }

    /**
     * حسابات العميل في نطاق الطلب مع فروعها ومندوبيها.
     *
     * @return array<string, \Closure>
     */
    private function accountsIn(?int $branchId): array
    {
        return ['accounts' => fn ($q) => $q
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with(['branch:id,name', 'agent:id,name'])];
    }

    /**
     * @param  Collection<int,int>  $ids
     * @return array<int|string, array<string, mixed>>
     */
    private function loadPageStats(Collection $ids, ?int $branchId): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        $stats = [];

        foreach (['service_invoices', 'product_invoices'] as $table) {
            if (! $this->hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)
                ->whereIn('customer_id', $ids)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
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
    private function computeFinancialSummary(Customer $customer, ?int $branchId): array
    {
        $totals = ['count' => 0, 'total' => 0.0, 'paid' => 0.0, 'due' => 0.0];
        $creditLimit = $customer->accountsSummary()['credit_limit'];

        foreach (['service_invoices', 'product_invoices'] as $table) {
            if (! $this->hasTable($table)) {
                continue;
            }

            $row = DB::table($table)
                ->where('customer_id', $customer->id)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
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
            'creditLimit' => $creditLimit,
            'availableCredit' => $creditLimit !== null
                ? max(0, (float) $creditLimit - $totals['due'])
                : null,
        ];
    }

    /** @return array<int, stdClass> */
    private function loadLoyaltyHistory(Request $request, Customer $customer, ?int $branchId): array
    {
        if (! $this->hasTable('loyalty_transactions')) {
            return ['data' => [], 'meta' => $this->pageMeta(1, 1, 0, self::HISTORY_PER_PAGE)];
        }

        $paginator = DB::table('loyalty_transactions')
            ->where('customer_id', $customer->id)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->orderByDesc('created_at')
            ->select(['id', 'type', 'points', 'balance_after', 'notes', 'created_at'])
            ->paginate(self::HISTORY_PER_PAGE, pageName: 'loyaltyPage')
            ->withQueryString();

        return $this->pagedProp($paginator);
    }

    /**
     * سجلّ فواتير العميل من الجدولين معاً.
     *
     * الدمج يجري في قاعدة البيانات لا في PHP: قراءةُ مئةٍ من كل جدول ثم فرزُها
     * وقصُّها في الذاكرة كانت تُخفي ما وراء ذلك ولا سبيل إلى بلوغه. كل فرعٍ من
     * الاتحاد يختار الأعمدة نفسها وبالترتيب نفسه — ومنها ثابتُ النوع — فينتظم
     * الفرز والترقيم على المجموع.
     *
     * @return array{data: list<mixed>, meta: array<string, int>}
     */
    private function loadInvoiceHistory(Request $request, Customer $customer, ?int $branchId): array
    {
        $columns = 'id, invoice_number, total_amount, status, created_at';

        $subQueries = [];

        foreach (['service_invoices' => 'service', 'product_invoices' => 'product'] as $table => $type) {
            if (! $this->hasTable($table)) {
                continue;
            }

            // ثابتُ النوع يُكتب حرفياً لا بارتباط، فترتيب الارتباطات في الاتحاد
            // لا يختلط — والقيمة من ثوابت الكود لا من المستخدم.
            $subQueries[] = DB::table($table)
                ->where('customer_id', $customer->id)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->whereNull('deleted_at')
                ->selectRaw($columns.", '{$type}' as type");
        }

        if ($subQueries === []) {
            return ['data' => [], 'meta' => $this->pageMeta(1, 1, 0, self::HISTORY_PER_PAGE)];
        }

        $union = array_shift($subQueries);

        foreach ($subQueries as $sub) {
            $union->unionAll($sub);
        }

        $paginator = DB::query()
            ->fromSub($union, 'invoices')
            ->orderByDesc('created_at')
            ->paginate(self::HISTORY_PER_PAGE, pageName: 'invoicePage')
            ->withQueryString();

        return $this->pagedProp($paginator);
    }

    /** فحصُ وجود جدول مرةً واحدة لكل طلب. */
    private function hasTable(string $table): bool
    {
        return $this->tableExists[$table] ??= Schema::hasTable($table);
    }

    /** @return array<int, array<string, mixed>> */
    private function buildOutstandingReport(?int $branchId): array
    {
        $customers = Customer::query()
            ->when($branchId !== null, fn ($q) => $q->inBranch($branchId))
            ->get(['id', 'full_name', 'phone', 'company_name', 'customer_type']);

        if ($customers->isEmpty()) {
            return [];
        }

        $ids = $customers->pluck('id');
        $totals = [];
        $oldestDue = [];

        foreach (['service_invoices', 'product_invoices'] as $table) {
            if (! $this->hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)
                ->whereIn('customer_id', $ids)
                ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
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
