<?php

namespace App\Http\Controllers;

use App\Actions\Report\ResolveReportScope;
use App\Enums\InvoiceStatusEnum;
use App\Http\Requests\Report\AnalyticsFilterRequest;
use App\Models\Branch;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    private const TABLES = ['product_invoices', 'service_invoices'];

    /** Default chart window when no explicit date range is picked. */
    private const DEFAULT_DAYS = 30;

    /** Beyond this span zero-filling would bloat the payload; only actual days are sent. */
    private const MAX_FILLED_DAYS = 366;

    public function index(AnalyticsFilterRequest $request, ResolveReportScope $resolveScope): Response
    {
        $scope = $resolveScope->handle($request);
        $scope['from'] ??= Carbon::today()->subDays(self::DEFAULT_DAYS - 1)->startOfDay();
        $scope['to'] ??= Carbon::now()->endOfDay();

        $daily = $this->dailyRevenue($scope);

        return Inertia::render('analytics/index', [
            'dailyRevenue' => $daily,
            'salesByType' => [
                'product' => array_sum(array_column($daily, 'product')),
                'service' => array_sum(array_column($daily, 'service')),
            ],
            'topServices' => $this->topServices($scope),
            'employeePerformance' => $this->employeePerformance($scope),
            'byBranch' => $scope['isSuper'] ? $this->byBranch($scope) : [],
            'loyalty' => [
                'tierDistribution' => $this->tierDistribution($scope),
                'pointsMonthly' => $this->pointsMonthly($scope),
            ],
            'filters' => [
                'from' => $scope['from']->toDateString(),
                'to' => $scope['to']->toDateString(),
                'branch' => $scope['isSuper'] && $scope['branchId'] ? (string) $scope['branchId'] : null,
            ],
            'branches' => $scope['isSuper']
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'isSuperAdmin' => $scope['isSuper'],
        ]);
    }

    /**
     * Base query for one invoice table with all scope filters applied. Only
     * paid invoices count as realized revenue, dated by paid_at. DB::table()
     * bypasses the SoftDeletes global scope, so deleted rows are excluded here.
     *
     * @param  array<string, mixed>  $scope
     */
    private function baseQuery(string $table, array $scope): Builder
    {
        return DB::table($table)
            ->where($table.'.status', InvoiceStatusEnum::PAID->value)
            ->whereNull($table.'.deleted_at')
            ->when($scope['branchId'], fn ($q) => $q->where($table.'.branch_id', $scope['branchId']))
            ->where($table.'.paid_at', '>=', $scope['from'])
            ->where($table.'.paid_at', '<=', $scope['to']);
    }

    /**
     * Daily paid revenue with product and service series. Days without sales
     * are zero-filled so the line stays continuous, unless the range is too
     * wide to send one point per day.
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array{date: string, product: float, service: float}>
     */
    private function dailyRevenue(array $scope): array
    {
        $days = [];

        $span = (int) $scope['from']->copy()->startOfDay()->diffInDays($scope['to']) + 1;
        if ($span <= self::MAX_FILLED_DAYS) {
            for ($i = 0; $i < $span; $i++) {
                $key = $scope['from']->copy()->addDays($i)->toDateString();
                $days[$key] = ['date' => $key, 'product' => 0.0, 'service' => 0.0];
            }
        }

        foreach (self::TABLES as $table) {
            $series = $table === 'product_invoices' ? 'product' : 'service';

            $rows = $this->baseQuery($table, $scope)
                ->groupBy(DB::raw('DATE(paid_at)'))
                ->get([
                    DB::raw('DATE(paid_at) as day'),
                    DB::raw('COALESCE(SUM(total_amount), 0) as total'),
                ]);

            foreach ($rows as $row) {
                $key = (string) $row->day;
                $days[$key] ??= ['date' => $key, 'product' => 0.0, 'service' => 0.0];
                $days[$key][$series] = (float) $row->total;
            }
        }

        ksort($days);

        return array_values($days);
    }

    /**
     * Top ten services in the range by revenue, from paid service invoices.
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array{name: string, count: int, total: float}>
     */
    private function topServices(array $scope): array
    {
        return DB::table('service_invoice_lines')
            ->join('service_invoices', 'service_invoices.id', '=', 'service_invoice_lines.invoice_id')
            ->whereNull('service_invoices.deleted_at')
            ->where('service_invoices.status', InvoiceStatusEnum::PAID->value)
            ->where('service_invoices.paid_at', '>=', $scope['from'])
            ->where('service_invoices.paid_at', '<=', $scope['to'])
            ->when($scope['branchId'], fn ($q) => $q->where('service_invoices.branch_id', $scope['branchId']))
            ->groupBy('service_invoice_lines.service_name')
            ->orderByDesc(DB::raw('SUM(service_invoice_lines.subtotal)'))
            ->limit(10)
            ->get([
                'service_invoice_lines.service_name as name',
                DB::raw('COUNT(*) as line_count'),
                DB::raw('COALESCE(SUM(service_invoice_lines.subtotal), 0) as total'),
            ])
            ->map(fn ($row) => [
                'name' => $row->name,
                'count' => (int) $row->line_count,
                'total' => (float) $row->total,
            ])
            ->all();
    }

    /**
     * Top ten employees by paid revenue, merged across both invoice tables.
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array{name: string, count: int, total: float}>
     */
    private function employeePerformance(array $scope): array
    {
        $employees = [];

        foreach (self::TABLES as $table) {
            $rows = $this->baseQuery($table, $scope)
                ->join('users', 'users.id', '=', $table.'.user_id')
                ->groupBy($table.'.user_id', 'users.name')
                ->get([
                    $table.'.user_id as user_id',
                    'users.name as user_name',
                    DB::raw('COUNT(*) as c'),
                    DB::raw('COALESCE(SUM('.$table.'.total_amount), 0) as total'),
                ]);

            foreach ($rows as $row) {
                $key = (int) $row->user_id;
                $employees[$key] ??= ['name' => $row->user_name, 'count' => 0, 'total' => 0.0];
                $employees[$key]['count'] += (int) $row->c;
                $employees[$key]['total'] += (float) $row->total;
            }
        }

        usort($employees, fn ($a, $b) => $b['total'] <=> $a['total']);

        return array_slice(array_values($employees), 0, 10);
    }

    /**
     * Paid revenue per branch for the super-admin comparison chart.
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array{name: string, count: int, total: float}>
     */
    private function byBranch(array $scope): array
    {
        $branches = [];

        foreach (self::TABLES as $table) {
            $rows = $this->baseQuery($table, $scope)
                ->join('branches', 'branches.id', '=', $table.'.branch_id')
                ->groupBy($table.'.branch_id', 'branches.name')
                ->get([
                    $table.'.branch_id as branch_id',
                    'branches.name as branch_name',
                    DB::raw('COUNT(*) as c'),
                    DB::raw('COALESCE(SUM('.$table.'.total_amount), 0) as total'),
                ]);

            foreach ($rows as $row) {
                $key = (int) $row->branch_id;
                $branches[$key] ??= ['name' => $row->branch_name, 'count' => 0, 'total' => 0.0];
                $branches[$key]['count'] += (int) $row->c;
                $branches[$key]['total'] += (float) $row->total;
            }
        }

        usort($branches, fn ($a, $b) => $b['total'] <=> $a['total']);

        return array_values($branches);
    }

    /**
     * Active customer count per loyalty tier, in ascending tier order. Tiers
     * reflect current state, so the date range does not apply here.
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array{tier: string, count: int}>
     */
    private function tierDistribution(array $scope): array
    {
        $counts = DB::table('customers')
            ->whereNull('deleted_at')
            ->when($scope['branchId'], fn ($q) => $q->where('branch_id', $scope['branchId']))
            ->groupBy('tier')
            ->select('tier', DB::raw('COUNT(*) as c'))
            ->pluck('c', 'tier');

        return collect(['none', 'bronze', 'silver', 'gold'])
            ->map(fn (string $tier) => ['tier' => $tier, 'count' => (int) ($counts[$tier] ?? 0)])
            ->all();
    }

    /**
     * Loyalty points earned vs redeemed per month across the range, zero-filled
     * so both lines are continuous. Redemptions are stored as negative points
     * and flipped positive for charting. Branch scope goes through the customer,
     * since the transactions table has no branch column.
     *
     * @param  array<string, mixed>  $scope
     * @return array<int, array{month: string, earned: int, redeemed: int}>
     */
    private function pointsMonthly(array $scope): array
    {
        $months = [];
        $cursor = $scope['from']->copy()->startOfMonth();
        while ($cursor <= $scope['to']) {
            $months[$cursor->format('Y-m')] = ['month' => $cursor->format('Y-m'), 'earned' => 0, 'redeemed' => 0];
            $cursor->addMonth();
        }

        // Grouped per day (portable across MySQL/SQLite), bucketed into months here.
        $rows = DB::table('loyalty_transactions')
            ->join('customers', 'customers.id', '=', 'loyalty_transactions.customer_id')
            ->when($scope['branchId'], fn ($q) => $q->where('customers.branch_id', $scope['branchId']))
            ->where('loyalty_transactions.created_at', '>=', $scope['from'])
            ->where('loyalty_transactions.created_at', '<=', $scope['to'])
            ->groupBy(DB::raw('DATE(loyalty_transactions.created_at)'))
            ->get([
                DB::raw('DATE(loyalty_transactions.created_at) as day'),
                DB::raw("COALESCE(SUM(CASE WHEN loyalty_transactions.type = 'earn' THEN loyalty_transactions.points ELSE 0 END), 0) as earned"),
                DB::raw("COALESCE(SUM(CASE WHEN loyalty_transactions.type = 'redeem' THEN -loyalty_transactions.points ELSE 0 END), 0) as redeemed"),
            ]);

        foreach ($rows as $row) {
            $key = substr((string) $row->day, 0, 7);
            $months[$key] ??= ['month' => $key, 'earned' => 0, 'redeemed' => 0];
            $months[$key]['earned'] += (int) $row->earned;
            $months[$key]['redeemed'] += (int) $row->redeemed;
        }

        ksort($months);

        return array_values($months);
    }
}
