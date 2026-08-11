<?php

namespace App\Http\Controllers;

use App\Actions\Report\BuildReportDayRange;
use App\Actions\Report\ExcludeReturnedCommission;
use App\Actions\Report\ResolveReportScope;
use App\Enums\InvoiceStatusEnum;
use App\Enums\StockMovementTypeEnum;
use App\Exports\DailyReportExport;
use App\Http\Requests\Report\DailyReportFilterRequest;
use App\Models\Branch;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Daily employee/branch report: one row per calendar day with product and
 * service sales (net of discounts, before VAT), realized employee commission,
 * purchases (expenses + received stock), VAT, and the net remaining amount.
 *
 * Sales count ALL non-cancelled invoices dated by created_at (not only paid),
 * commission comes from the realized commission_ledger dated by earned_at, and
 * purchases combine expenses and purchase-order receipts. When at least one
 * employee is selected the purchases/remaining columns are hidden, since those
 * are a branch-level figure not attributable to individual salespeople.
 *
 * Beside those accrual figures sits «المحصَّل»: the money that actually came in
 * that day, read from invoice_payments (عربون + دفعات لاحقة) and dated by each
 * payment. It is deliberately not the same number as «الإجمالي» — a day may
 * raise invoices it has not collected, and collect on invoices raised earlier.
 * An invoice settled outright at the till carries no payment rows, so its whole
 * total is counted on its paid_at day.
 *
 * Several employees may be selected at once. With two or more the report turns
 * "detailed": one row per (day × employee) followed by a per-day total row. With
 * none or exactly one it keeps the plain one-row-per-day shape.
 */
class DailyReportController extends Controller
{
    public function __construct(private readonly BuildReportDayRange $dayRange) {}

    public function index(DailyReportFilterRequest $request, ResolveReportScope $resolveScope): Response
    {
        $scope = $resolveScope->handle($request);
        $employeeIds = $this->employeeFilter($request);
        $detailed = count($employeeIds) > 1;

        $rows = $this->rows($scope, $employeeIds, $detailed);

        return Inertia::render('reports/daily/index', [
            'rows' => $rows,
            'totals' => $this->totals($rows, $employeeIds === []),
            'showPurchases' => $employeeIds === [],
            'detailed' => $detailed,
            'filters' => [
                'from' => $scope['from']?->toDateString(),
                'to' => $scope['to']?->toDateString(),
                'branch' => $scope['isSuper'] && $scope['branchId'] ? (string) $scope['branchId'] : null,
                'employee' => $employeeIds !== [] ? implode(',', $employeeIds) : null,
            ],
            // The "cleared" value of the date fields — the report opens on today,
            // so the client treats today as the default and not as a live filter.
            'defaultDate' => Carbon::today()->toDateString(),
            'branches' => $scope['isSuper']
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'employees' => $this->employeeOptions($scope['branchId']),
            'isSuperAdmin' => $scope['isSuper'],
        ]);
    }

    public function export(DailyReportFilterRequest $request, ResolveReportScope $resolveScope): BinaryFileResponse|HttpResponse
    {
        $scope = $resolveScope->handle($request);
        $employeeIds = $this->employeeFilter($request);
        $detailed = count($employeeIds) > 1;

        return Excel::download(
            new DailyReportExport($this->rows($scope, $employeeIds, $detailed), $employeeIds === [], $detailed),
            'daily-report-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    /**
     * Only super-admin, branch-admin and accountant reach this report, so the
     * employee filter is a free selection (never forced to the actor's own id).
     * The request normalizes the raw input into a list of ids.
     *
     * @return array<int, int>
     */
    private function employeeFilter(DailyReportFilterRequest $request): array
    {
        return collect((array) $request->input('employee', []))
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Build the merged rows across all sources, sorted chronologically. In
     * detailed mode each day yields one row per employee (ordered by name)
     * followed by that day's total row; otherwise one row per day.
     *
     * @param  array{isSuper: bool, branchId: ?int, from: ?Carbon, to: ?Carbon}  $scope
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(array $scope, array $employeeIds, bool $detailed): Collection
    {
        $names = $detailed ? $this->employeeNames($employeeIds) : [];
        $showPurchases = $employeeIds === [];

        /** @var array<string, array<int, array<string, mixed>>> $buckets day => employee id (0 = whole day) => row */
        $buckets = [];

        // Captures $buckets by reference so each source can seed a fresh row.
        $ensure = function (string $day, int $employeeId) use (&$buckets, $names): void {
            $buckets[$day][$employeeId] ??= [
                'date' => $day,
                'employeeId' => $employeeId !== 0 ? $employeeId : null,
                'employeeName' => $employeeId !== 0 ? ($names[$employeeId] ?? '—') : null,
                'isTotal' => false,
                'products' => 0.0,
                'services' => 0.0,
                'total' => 0.0,
                'collected' => 0.0,
                'refunds' => 0.0,
                'refundsDeductible' => 0.0,
                'commission' => 0.0,
                'purchases' => 0.0,
                'remaining' => 0.0,
                'vat' => 0.0,
            ];
        };

        // Seed every day of the range up front so a quiet day still gets a row
        // reading 0.00 instead of dropping out of the table. In detailed mode a
        // day yields one row per selected employee, matching the populated days.
        foreach ($this->dayRange->handle($scope) as $day) {
            if ($detailed) {
                foreach ($employeeIds as $employeeId) {
                    $ensure($day, $employeeId);
                }
            } else {
                $ensure($day, 0);
            }
        }

        foreach (['product_invoices' => 'products', 'service_invoices' => 'services'] as $table => $key) {
            foreach ($this->invoiceDaily($table, $scope, $employeeIds, $detailed) as $row) {
                $day = (string) $row->day;
                $employeeId = $detailed ? (int) $row->user_id : 0;
                $ensure($day, $employeeId);
                $buckets[$day][$employeeId][$key] += (float) $row->net;
                $buckets[$day][$employeeId]['total'] += (float) $row->net;
                $buckets[$day][$employeeId]['vat'] += (float) $row->vat;
            }
        }

        foreach ($this->collectedDaily($scope, $employeeIds, $detailed) as $row) {
            $day = (string) $row->day;
            $employeeId = $detailed ? (int) $row->user_id : 0;
            $ensure($day, $employeeId);
            $buckets[$day][$employeeId]['collected'] += (float) $row->collected;
        }

        // المرتجعات تُعرض في عمودها كاملةً — العميل يريد رؤيتها، لا إخفاء صفوفها —
        // بينما لا يُطرح من المحصَّل إلا ما لم يُطرح مرة أخرى أصلاً (انظر تعليق
        // refundsDaily).
        foreach ($this->refundsDaily($scope, $employeeIds, $detailed) as $row) {
            $day = (string) $row->day;
            $employeeId = $detailed ? (int) $row->user_id : 0;
            $ensure($day, $employeeId);
            $buckets[$day][$employeeId]['refunds'] += (float) $row->refunded;
            $buckets[$day][$employeeId]['refundsDeductible'] += (float) $row->deductible;
        }

        foreach ($this->commissionDaily($scope, $employeeIds, $detailed) as $row) {
            $day = (string) $row->day;
            $employeeId = $detailed ? (int) $row->user_id : 0;
            $ensure($day, $employeeId);
            $buckets[$day][$employeeId]['commission'] += (float) $row->commission;
        }

        // Purchases are a branch-level figure; skip them when filtering employees.
        if ($showPurchases) {
            foreach ($this->purchasesDaily($scope) as $day => $amount) {
                $ensure($day, 0);
                $buckets[$day][0]['purchases'] += $amount;
            }
        }

        ksort($buckets);

        $rows = [];

        foreach ($buckets as $day => $dayRows) {
            uasort($dayRows, fn (array $a, array $b) => strcmp((string) $a['employeeName'], (string) $b['employeeName']));

            foreach ($dayRows as $row) {
                // المرتجع مالٌ خرج فعلاً، فيُخصم من المحصَّل ومن الصافي المتبقي —
                // لكن الجزء القابل للخصم فقط، وإلا خرجت الفاتورة المرتجعة كلياً
                // مرتين فصار المحصَّل بالسالب.
                $row['collected'] -= $row['refundsDeductible'];
                $row['remaining'] = $showPurchases
                    ? $row['total'] - $row['refundsDeductible'] - $row['commission'] - $row['purchases']
                    : 0.0;

                $rows[] = $row;
            }

            if ($detailed) {
                $rows[] = $this->dayTotalRow((string) $day, $dayRows);
            }
        }

        return collect($rows);
    }

    /**
     * Closing row summing every employee row of a single day (detailed mode).
     * Purchases/remaining stay zero — they are hidden whenever employees are
     * filtered.
     *
     * @param  array<int, array<string, mixed>>  $dayRows
     * @return array<string, mixed>
     */
    private function dayTotalRow(string $day, array $dayRows): array
    {
        $sum = fn (string $key): float => (float) array_sum(array_column($dayRows, $key));

        return [
            'date' => $day,
            'employeeId' => null,
            'employeeName' => null,
            'isTotal' => true,
            'products' => $sum('products'),
            'services' => $sum('services'),
            'total' => $sum('total'),
            // صفوف اليوم خُصمت منها مرتجعاتها قبل بلوغ هذه الدالة، فالمجموع
            // يجمع محصَّلاً صافياً.
            'collected' => $sum('collected'),
            'refunds' => $sum('refunds'),
            'refundsDeductible' => $sum('refundsDeductible'),
            'commission' => $sum('commission'),
            'purchases' => 0.0,
            'remaining' => 0.0,
            'vat' => $sum('vat'),
        ];
    }

    /**
     * Net sales (subtotal − discounts, before VAT) and VAT for one invoice
     * table, grouped by created_at day (and by employee in detailed mode).
     * Cancelled and soft-deleted invoices are excluded; DB::table() bypasses the
     * SoftDeletes scope so deleted_at is filtered explicitly.
     *
     * @param  array<string, mixed>  $scope
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, \stdClass>
     */
    private function invoiceDaily(string $table, array $scope, array $employeeIds, bool $detailed): Collection
    {
        // صافي المبيعات قبل الضريبة = الإجمالي − الضريبة. تُشتقّ من العمودين
        // المخزّنين لا من (subtotal − الخصومات): الأخيرة تساوي الإجمالي شامل
        // الضريبة منذ أن صارت أسعار نقطة البيع شاملة لها، فكانت ستُدخل الضريبة
        // في «الصافي». الصيغة الحالية صحيحة للفواتير القديمة والجديدة معاً.
        $columns = [
            DB::raw('DATE('.$table.'.created_at) as day'),
            DB::raw('COALESCE(SUM(total_amount - vat_amount), 0) as net'),
            DB::raw('COALESCE(SUM(vat_amount), 0) as vat'),
        ];

        if ($detailed) {
            $columns[] = $table.'.user_id';
        }

        return DB::table($table)
            ->whereNotIn($table.'.status', InvoiceStatusEnum::excludedFromRevenue())
            ->whereNull($table.'.deleted_at')
            ->when($scope['branchId'], fn ($q) => $q->where($table.'.branch_id', $scope['branchId']))
            ->when($employeeIds !== [], fn ($q) => $q->whereIn($table.'.user_id', $employeeIds))
            ->when($scope['from'], fn ($q) => $q->where($table.'.created_at', '>=', $scope['from']))
            ->when($scope['to'], fn ($q) => $q->where($table.'.created_at', '<=', $scope['to']))
            ->groupBy(DB::raw('DATE('.$table.'.created_at)'))
            ->when($detailed, fn ($q) => $q->groupBy($table.'.user_id'))
            ->get($columns);
    }

    /**
     * ما حُصِّل فعلاً كل يوم: دفعات الفاتورة (عربون + دفعات لاحقة) مؤرَّخةً بيوم
     * قبضها، تُضاف إليها الفواتير التي سُدِّدت عند البيع بلا صفوف دفعات فتُقيَّد
     * بكامل إجمالها في يوم سدادها. تُنسب الدفعة لمنشئ فاتورتها في الوضع المفصّل.
     *
     * @param  array<string, mixed>  $scope
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, \stdClass>
     */
    private function collectedDaily(array $scope, array $employeeIds, bool $detailed): Collection
    {
        $rows = collect();

        foreach ([ProductInvoice::class, ServiceInvoice::class] as $model) {
            $table = (new $model)->getTable();

            $columns = [
                DB::raw('DATE(p.paid_at) as day'),
                DB::raw('COALESCE(SUM(p.amount), 0) as collected'),
            ];

            $direct = [
                DB::raw('DATE(i.paid_at) as day'),
                DB::raw('COALESCE(SUM(i.total_amount), 0) as collected'),
            ];

            if ($detailed) {
                $columns[] = 'i.user_id';
                $direct[] = 'i.user_id';
            }

            $payments = DB::table('invoice_payments as p')
                ->join($table.' as i', 'i.id', '=', 'p.invoice_id')
                ->where('p.invoice_type', $model)
                ->whereNull('i.deleted_at')
                ->whereNotIn('i.status', InvoiceStatusEnum::excludedFromRevenue())
                ->when($scope['branchId'], fn ($q) => $q->where('i.branch_id', $scope['branchId']))
                ->when($employeeIds !== [], fn ($q) => $q->whereIn('i.user_id', $employeeIds))
                ->when($scope['from'], fn ($q) => $q->where('p.paid_at', '>=', $scope['from']))
                ->when($scope['to'], fn ($q) => $q->where('p.paid_at', '<=', $scope['to']))
                ->groupBy(DB::raw('DATE(p.paid_at)'))
                ->when($detailed, fn ($q) => $q->groupBy('i.user_id'))
                ->get($columns);

            $settledAtTill = DB::table($table.' as i')
                ->whereNull('i.deleted_at')
                ->where('i.status', InvoiceStatusEnum::PAID->value)
                ->whereNotNull('i.paid_at')
                ->whereNotExists(fn ($q) => $q->from('invoice_payments as p')
                    ->where('p.invoice_type', $model)
                    ->whereColumn('p.invoice_id', 'i.id'))
                ->when($scope['branchId'], fn ($q) => $q->where('i.branch_id', $scope['branchId']))
                ->when($employeeIds !== [], fn ($q) => $q->whereIn('i.user_id', $employeeIds))
                ->when($scope['from'], fn ($q) => $q->where('i.paid_at', '>=', $scope['from']))
                ->when($scope['to'], fn ($q) => $q->where('i.paid_at', '<=', $scope['to']))
                ->groupBy(DB::raw('DATE(i.paid_at)'))
                ->when($detailed, fn ($q) => $q->groupBy('i.user_id'))
                ->get($direct);

            $rows = $rows->concat($payments)->concat($settledAtTill);
        }

        return $rows;
    }

    /**
     * ما رُدَّ فعلاً للعملاء كل يوم، مؤرَّخاً بيوم تسجيل المرتجع. يشمل المرتجع
     * الجزئي والكامل، ويُنسب لمنشئ الفاتورة في الوضع المفصّل لا لمن سجّل المرتجع
     * — الأثر على مبيعات ذلك الموظف لا على من نفّذ الردّ.
     *
     * جدول refunds كان خارج هذا التقرير كلياً، فكان المحصَّل مضخّماً بقيمة كل
     * مرتجع جزئي.
     *
     * لكل يوم رقمان: `refunded` وهو كل ما رُدّ ويُعرض في العمود، و`deductible`
     * وهو ما يُطرح فعلاً من المحصَّل والمتبقي. الفاتورة المرتجعة بالكامل تصير
     * حالتها `returned` فتسقط أصلاً من المبيعات ومن المحصَّل، فطرحُ صفّ مرتجعها
     * فوق ذلك خصمٌ ثانٍ لنفس المبلغ — وهو ما كان يُخرج المحصَّل بالسالب. أما
     * المرتجع الجزئي فتبقى فاتورته محتسبة، فيُخصم كما هو.
     *
     * @param  array<string, mixed>  $scope
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, \stdClass>
     */
    private function refundsDaily(array $scope, array $employeeIds, bool $detailed): Collection
    {
        $rows = collect();

        // قيم enum ثابتة لا مدخلات مستخدم، فاقتباسها هنا آمن.
        $excluded = collect(InvoiceStatusEnum::excludedFromRevenue())
            ->map(fn (string $status) => "'".$status."'")
            ->implode(', ');

        foreach ([ProductInvoice::class, ServiceInvoice::class] as $model) {
            $table = (new $model)->getTable();

            $columns = [
                DB::raw('DATE(r.created_at) as day'),
                DB::raw('COALESCE(SUM(r.amount), 0) as refunded'),
                DB::raw('COALESCE(SUM(CASE WHEN i.status IN ('.$excluded.') THEN 0 ELSE r.amount END), 0) as deductible'),
            ];

            if ($detailed) {
                $columns[] = 'i.user_id';
            }

            $rows = $rows->concat(
                DB::table('refunds as r')
                    ->join($table.' as i', 'i.id', '=', 'r.invoice_id')
                    ->where('r.invoice_type', $model)
                    ->whereNull('r.deleted_at')
                    ->whereNull('i.deleted_at')
                    ->when($scope['branchId'], fn ($q) => $q->where('r.branch_id', $scope['branchId']))
                    ->when($employeeIds !== [], fn ($q) => $q->whereIn('i.user_id', $employeeIds))
                    ->when($scope['from'], fn ($q) => $q->where('r.created_at', '>=', $scope['from']))
                    ->when($scope['to'], fn ($q) => $q->where('r.created_at', '<=', $scope['to']))
                    ->groupBy(DB::raw('DATE(r.created_at)'))
                    ->when($detailed, fn ($q) => $q->groupBy('i.user_id'))
                    ->get($columns)
            );
        }

        return $rows;
    }

    /**
     * Realized commission (commission_ledger) grouped by earned_at day (and by
     * employee in detailed mode).
     *
     * تُسقَط منه صفوف الفواتير المرتجعة غير المدفوعة، تماماً كما يفعل تقرير
     * العمولات (تاسك 10)، عبر ExcludeReturnedCommission المشترك — وإلا أعطى
     * التقريران رقمين مختلفين لليوم نفسه.
     *
     * @param  array<string, mixed>  $scope
     * @param  array<int, int>  $employeeIds
     * @return Collection<int, \stdClass>
     */
    private function commissionDaily(array $scope, array $employeeIds, bool $detailed): Collection
    {
        $columns = [
            DB::raw('DATE(earned_at) as day'),
            DB::raw('COALESCE(SUM(amount), 0) as commission'),
        ];

        if ($detailed) {
            $columns[] = 'user_id';
        }

        $query = DB::table('commission_ledger')
            ->when($scope['branchId'], fn ($q) => $q->where('branch_id', $scope['branchId']))
            ->when($employeeIds !== [], fn ($q) => $q->whereIn('user_id', $employeeIds))
            ->when($scope['from'], fn ($q) => $q->where('earned_at', '>=', $scope['from']))
            ->when($scope['to'], fn ($q) => $q->where('earned_at', '<=', $scope['to']));

        ExcludeReturnedCommission::apply($query);

        return $query
            ->groupBy(DB::raw('DATE(earned_at)'))
            ->when($detailed, fn ($q) => $q->groupBy('user_id'))
            ->get($columns);
    }

    /**
     * Purchases per day = branch expenses (by expense date) + received stock
     * value (purchase_in movements, qty × unit_cost, by movement date). Both
     * halves are merged into a single day => amount map.
     *
     * @param  array<string, mixed>  $scope
     * @return array<string, float>
     */
    private function purchasesDaily(array $scope): array
    {
        $daily = [];

        $expenses = DB::table('expenses')
            ->whereNull('deleted_at')
            ->when($scope['branchId'], fn ($q) => $q->where('branch_id', $scope['branchId']))
            ->when($scope['from'], fn ($q) => $q->where('date', '>=', $scope['from']))
            ->when($scope['to'], fn ($q) => $q->where('date', '<=', $scope['to']))
            ->groupBy('date')
            ->get([
                DB::raw('DATE(date) as day'),
                DB::raw('COALESCE(SUM(total), 0) as amount'),
            ]);

        foreach ($expenses as $row) {
            $day = (string) $row->day;
            $daily[$day] = ($daily[$day] ?? 0.0) + (float) $row->amount;
        }

        $stock = DB::table('stock_movements')
            ->where('type', StockMovementTypeEnum::PURCHASE_IN->value)
            ->when($scope['branchId'], fn ($q) => $q->where('branch_id', $scope['branchId']))
            ->when($scope['from'], fn ($q) => $q->where('created_at', '>=', $scope['from']))
            ->when($scope['to'], fn ($q) => $q->where('created_at', '<=', $scope['to']))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->get([
                DB::raw('DATE(created_at) as day'),
                DB::raw('COALESCE(SUM(qty * unit_cost), 0) as amount'),
            ]);

        foreach ($stock as $row) {
            $day = (string) $row->day;
            $daily[$day] = ($daily[$day] ?? 0.0) + (float) $row->amount;
        }

        return $daily;
    }

    /**
     * Column grand totals across every day in the report. Per-day total rows are
     * skipped so detailed mode does not count its figures twice.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    private function totals(Collection $rows, bool $showPurchases): array
    {
        $details = $rows->reject(fn (array $row) => $row['isTotal']);

        return [
            'dayCount' => $details->pluck('date')->unique()->count(),
            'products' => (float) $details->sum('products'),
            'services' => (float) $details->sum('services'),
            'total' => (float) $details->sum('total'),
            'collected' => (float) $details->sum('collected'),
            'refunds' => (float) $details->sum('refunds'),
            'commission' => (float) $details->sum('commission'),
            'purchases' => $showPurchases ? (float) $details->sum('purchases') : 0.0,
            'remaining' => $showPurchases ? (float) $details->sum('remaining') : 0.0,
            'vat' => (float) $details->sum('vat'),
        ];
    }

    /**
     * Selectable employees for the filter, scoped to the branch when one is
     * fixed. Agents are excluded — they never create invoices.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    private function employeeOptions(?int $branchId): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'agent'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => (int) $u->id, 'name' => (string) $u->name])
            ->values();
    }

    /**
     * id => name map for the employees carrying the detailed rows.
     *
     * @param  array<int, int>  $employeeIds
     * @return array<int, string>
     */
    private function employeeNames(array $employeeIds): array
    {
        return User::query()
            ->whereKey($employeeIds)
            ->pluck('name', 'id')
            ->mapWithKeys(fn ($name, $id) => [(int) $id => (string) $name])
            ->all();
    }
}
