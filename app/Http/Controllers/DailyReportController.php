<?php

namespace App\Http\Controllers;

use App\Actions\Report\ResolveReportScope;
use App\Enums\InvoiceStatusEnum;
use App\Enums\StockMovementTypeEnum;
use App\Exports\DailyReportExport;
use App\Http\Requests\Report\DailyReportFilterRequest;
use App\Models\Branch;
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
 * purchases combine expenses and purchase-order receipts. When an employee is
 * selected the purchases/remaining columns are hidden, since those are a
 * branch-level figure not attributable to a single salesperson.
 */
class DailyReportController extends Controller
{
    /** Sum of every discount column, shared by every invoice aggregate. */
    private const DISCOUNTS = '(tier_discount_amount + coupon_discount + points_discount + agent_discount)';

    public function index(DailyReportFilterRequest $request, ResolveReportScope $resolveScope): Response
    {
        $scope = $resolveScope->handle($request);
        $employeeId = $this->employeeFilter($request);

        $rows = $this->rows($scope, $employeeId);

        return Inertia::render('reports/daily/index', [
            'rows' => $rows,
            'totals' => $this->totals($rows, $employeeId === null),
            'showPurchases' => $employeeId === null,
            'filters' => [
                'from' => $scope['from']?->toDateString(),
                'to' => $scope['to']?->toDateString(),
                'branch' => $scope['isSuper'] && $scope['branchId'] ? (string) $scope['branchId'] : null,
                'employee' => $employeeId !== null ? (string) $employeeId : null,
            ],
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
        $employeeId = $this->employeeFilter($request);

        return Excel::download(
            new DailyReportExport($this->rows($scope, $employeeId), $employeeId === null),
            'daily-report-'.now()->format('Y-m-d').'.xlsx',
        );
    }

    /**
     * Only super-admin, branch-admin and accountant reach this report, so the
     * employee filter is a free selection (never forced to the actor's own id).
     */
    private function employeeFilter(DailyReportFilterRequest $request): ?int
    {
        return $request->filled('employee') ? (int) $request->input('employee') : null;
    }

    /**
     * Build the merged per-day rows across all sources, sorted chronologically.
     *
     * @param  array{isSuper: bool, branchId: ?int, from: ?Carbon, to: ?Carbon}  $scope
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(array $scope, ?int $employeeId): Collection
    {
        $days = [];

        // Captures $days by reference so each source can seed a fresh day row.
        $ensure = function (string $day) use (&$days): void {
            $days[$day] ??= [
                'date' => $day,
                'products' => 0.0,
                'services' => 0.0,
                'total' => 0.0,
                'commission' => 0.0,
                'purchases' => 0.0,
                'remaining' => 0.0,
                'vat' => 0.0,
            ];
        };

        foreach (['product_invoices' => 'products', 'service_invoices' => 'services'] as $table => $key) {
            foreach ($this->invoiceDaily($table, $scope, $employeeId) as $row) {
                $day = (string) $row->day;
                $ensure($day);
                $days[$day][$key] += (float) $row->net;
                $days[$day]['total'] += (float) $row->net;
                $days[$day]['vat'] += (float) $row->vat;
            }
        }

        foreach ($this->commissionDaily($scope, $employeeId) as $row) {
            $day = (string) $row->day;
            $ensure($day);
            $days[$day]['commission'] += (float) $row->commission;
        }

        // Purchases are a branch-level figure; skip them when viewing one employee.
        if ($employeeId === null) {
            foreach ($this->purchasesDaily($scope) as $day => $amount) {
                $ensure($day);
                $days[$day]['purchases'] += $amount;
            }
        }

        ksort($days);

        return collect(array_values($days))->map(function (array $row) use ($employeeId) {
            $row['remaining'] = $employeeId === null
                ? $row['total'] - $row['commission'] - $row['purchases']
                : 0.0;

            return $row;
        });
    }

    /**
     * Net sales (subtotal − discounts, before VAT) and VAT for one invoice
     * table, grouped by created_at day. Cancelled and soft-deleted invoices are
     * excluded; DB::table() bypasses the SoftDeletes scope so deleted_at is
     * filtered explicitly.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, \stdClass>
     */
    private function invoiceDaily(string $table, array $scope, ?int $employeeId): Collection
    {
        return DB::table($table)
            ->where($table.'.status', '!=', InvoiceStatusEnum::CANCELLED->value)
            ->whereNull($table.'.deleted_at')
            ->when($scope['branchId'], fn ($q) => $q->where($table.'.branch_id', $scope['branchId']))
            ->when($employeeId, fn ($q) => $q->where($table.'.user_id', $employeeId))
            ->when($scope['from'], fn ($q) => $q->where($table.'.created_at', '>=', $scope['from']))
            ->when($scope['to'], fn ($q) => $q->where($table.'.created_at', '<=', $scope['to']))
            ->groupBy(DB::raw('DATE('.$table.'.created_at)'))
            ->get([
                DB::raw('DATE('.$table.'.created_at) as day'),
                DB::raw('COALESCE(SUM(subtotal - '.self::DISCOUNTS.'), 0) as net'),
                DB::raw('COALESCE(SUM(vat_amount), 0) as vat'),
            ]);
    }

    /**
     * Realized commission (commission_ledger) grouped by earned_at day.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, \stdClass>
     */
    private function commissionDaily(array $scope, ?int $employeeId): Collection
    {
        return DB::table('commission_ledger')
            ->when($scope['branchId'], fn ($q) => $q->where('branch_id', $scope['branchId']))
            ->when($employeeId, fn ($q) => $q->where('user_id', $employeeId))
            ->when($scope['from'], fn ($q) => $q->where('earned_at', '>=', $scope['from']))
            ->when($scope['to'], fn ($q) => $q->where('earned_at', '<=', $scope['to']))
            ->groupBy(DB::raw('DATE(earned_at)'))
            ->get([
                DB::raw('DATE(earned_at) as day'),
                DB::raw('COALESCE(SUM(amount), 0) as commission'),
            ]);
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
     * Column grand totals across every day in the report.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    private function totals(Collection $rows, bool $showPurchases): array
    {
        return [
            'dayCount' => $rows->count(),
            'products' => (float) $rows->sum('products'),
            'services' => (float) $rows->sum('services'),
            'total' => (float) $rows->sum('total'),
            'commission' => (float) $rows->sum('commission'),
            'purchases' => $showPurchases ? (float) $rows->sum('purchases') : 0.0,
            'remaining' => $showPurchases ? (float) $rows->sum('remaining') : 0.0,
            'vat' => (float) $rows->sum('vat'),
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
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values();
    }
}
