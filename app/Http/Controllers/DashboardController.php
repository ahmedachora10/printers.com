<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
use App\Models\EmployeeDeduction;
use App\Models\IncentivePlan;
use App\Models\Product;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const TABLES = ['product_invoices', 'service_invoices'];

    /** Days shown in the revenue trend and the rolling chart window. */
    private const TREND_DAYS = 30;

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $role = $user->roleName;

        // Agents have their own portal; the dashboard has nothing for them.
        if ($role === Roles::AGENT) {
            return redirect()->route('agent-portal.index');
        }

        $isSuper = $role?->isSuperAdmin() ?? false;
        $isAdmin = $isSuper || $role === Roles::BRANCH_ADMIN;
        $isAccountant = $role === Roles::ACCOUNTANT;
        $isEmployee = $role?->isEmployee() ?? false;

        // Super-admin sees every branch; everyone else is pinned to their own.
        $branchId = $isSuper ? null : $user->branchId;
        // Employees only ever see their own sales, dues and commissions.
        $userId = $isEmployee ? $user->id : null;

        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();
        $windowStart = $today->copy()->subDays(self::TREND_DAYS - 1)->startOfDay();

        $trend = $this->revenueTrend($branchId, $userId, $windowStart);

        return Inertia::render('dashboard', [
            'kpis' => [
                'todaySales' => $isAdmin || $isAccountant
                    ? $this->paidSalesBetween($branchId, $userId, $today->copy()->startOfDay(), $today->copy()->endOfDay())
                    : null,
                'monthSales' => $this->paidSalesBetween($branchId, $userId, $monthStart, Carbon::now()),
                'outstandingDue' => $isAdmin || $isAccountant ? $this->outstandingDue($branchId, $userId) : null,
                'pendingCommissions' => $isAdmin || $isEmployee ? $this->pendingCommissions($branchId, $userId) : null,
                // Inventory is a manager concern; hidden for accountants/employees.
                'lowStockCount' => $isAdmin ? $this->lowStockCount($branchId) : null,
            ],
            // Charts. Everyone gets a trend and their top services; the type and
            // payment-method breakdowns are for managers and accountants only.
            'revenueTrend' => $trend,
            'salesByType' => $isEmployee ? null : [
                'product' => array_sum(array_column($trend, 'product')),
                'service' => array_sum(array_column($trend, 'service')),
            ],
            'paymentMethods' => $isEmployee ? null : $this->paymentMethods($branchId, $userId, $windowStart),
            'topServices' => $this->topServices($branchId, $userId, $windowStart),
            'recentInvoices' => $this->recentInvoices($branchId, $userId),
            'incentive' => $isEmployee ? $this->incentive($user->id) : null,
            // الخصومات تُعرض للموظف دائماً ولو كانت صفراً: الغرض ألّا يفاجئه الحسم
            // في كشف الراتب، وكارتٌ يختفي عند الصفر لا يُطمئن أحداً.
            'deductions' => $isEmployee ? $this->deductions($user->id) : null,
            'scope' => [
                'isSuper' => $isSuper,
                'isEmployee' => $isEmployee,
                'userName' => $user->name,
                'trendDays' => self::TREND_DAYS,
            ],
        ]);
    }

    /**
     * Base query for one invoice table, scoped to branch/user. DB::table()
     * bypasses the SoftDeletes global scope, so deleted rows are excluded here.
     */
    private function scoped(string $table, ?int $branchId, ?int $userId): Builder
    {
        return DB::table($table)
            ->whereNull($table . '.deleted_at')
            ->when($branchId, fn($q) => $q->where($table . '.branch_id', $branchId))
            ->when($userId, fn($q) => $q->where($table . '.user_id', $userId));
    }

    /** Realized revenue (paid invoices) across both tables within a window. */
    private function paidSalesBetween(?int $branchId, ?int $userId, Carbon $from, Carbon $to): float
    {
        $total = 0.0;

        foreach (self::TABLES as $table) {
            $total += (float) $this->scoped($table, $branchId, $userId)
                ->where('status', InvoiceStatusEnum::PAID->value)
                ->whereBetween('paid_at', [$from, $to])
                ->sum('total_amount');
        }

        return $total;
    }

    /**
     * Daily paid revenue for the last TREND_DAYS days, one point per day with
     * product and service series. Missing days are filled with zero so the line
     * is continuous.
     *
     * @return array<int, array{date: string, product: float, service: float}>
     */
    private function revenueTrend(?int $branchId, ?int $userId, Carbon $windowStart): array
    {
        $days = [];
        for ($i = 0; $i < self::TREND_DAYS; $i++) {
            $key = $windowStart->copy()->addDays($i)->toDateString();
            $days[$key] = ['date' => $key, 'product' => 0.0, 'service' => 0.0];
        }

        foreach (self::TABLES as $table) {
            $series = $table === 'product_invoices' ? 'product' : 'service';

            $rows = $this->scoped($table, $branchId, $userId)
                ->where('status', InvoiceStatusEnum::PAID->value)
                ->where('paid_at', '>=', $windowStart)
                ->groupBy(DB::raw('DATE(paid_at)'))
                ->get([
                    DB::raw('DATE(paid_at) as day'),
                    DB::raw('COALESCE(SUM(total_amount), 0) as total'),
                ]);

            foreach ($rows as $row) {
                $key = (string) $row->day;
                if (isset($days[$key])) {
                    $days[$key][$series] = (float) $row->total;
                }
            }
        }

        return array_values($days);
    }

    /**
     * Paid revenue per payment method over the window, merged across both
     * tables. Invoices without one are grouped as "unspecified".
     *
     * @return array<int, array{name: string, total: float}>
     */
    private function paymentMethods(?int $branchId, ?int $userId, Carbon $windowStart): array
    {
        $methods = [];

        foreach (self::TABLES as $table) {
            $rows = $this->scoped($table, $branchId, $userId)
                ->where($table . '.status', InvoiceStatusEnum::PAID->value)
                ->where($table . '.paid_at', '>=', $windowStart)
                ->leftJoin('payment_methods', 'payment_methods.id', '=', $table . '.payment_method_id')
                ->groupBy($table . '.payment_method_id', 'payment_methods.name')
                ->get([
                    'payment_methods.name as name',
                    DB::raw('COALESCE(SUM(' . $table . '.total_amount), 0) as total'),
                ]);

            foreach ($rows as $row) {
                $name = $row->name ?? 'غير محدد';
                $methods[$name] = ($methods[$name] ?? 0.0) + (float) $row->total;
            }
        }

        $out = [];
        foreach ($methods as $name => $total) {
            $out[] = ['name' => $name, 'total' => $total];
        }
        usort($out, fn($a, $b) => $b['total'] <=> $a['total']);

        return $out;
    }

    /** Total value of unpaid (due) invoices. */
    private function outstandingDue(?int $branchId, ?int $userId): float
    {
        $total = 0.0;

        foreach (self::TABLES as $table) {
            $total += (float) $this->scoped($table, $branchId, $userId)
                ->where('status', InvoiceStatusEnum::DUE->value)
                ->sum('total_amount');
        }

        return $total;
    }

    /** Unpaid commission owed, from the immutable ledger. */
    private function pendingCommissions(?int $branchId, ?int $userId): float
    {
        return (float) DB::table('commission_ledger')
            ->whereNull('paid_at')
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->sum('amount');
    }

    /** Active products at or below their minimum stock level. */
    private function lowStockCount(?int $branchId): int
    {
        return Product::query()
            ->when($branchId, fn($q) => $q->where('branch_id', $branchId))
            ->whereColumn('current_stock', '<=', 'min_stock_level')
            ->where('is_active', true)
            ->count();
    }

    /**
     * The employee's active incentive plan for the current month, if any.
     *
     * @return array{target: float, achieved: float, pct: float, bonus: float, status: string}|null
     */
    private function incentive(int $userId): ?array
    {
        $plan = IncentivePlan::query()
            ->where('user_id', $userId)
            ->where('period_month', Carbon::now()->month)
            ->where('period_year', Carbon::now()->year)
            ->first();

        if (!$plan) {
            return null;
        }

        $target = (float) $plan->target_amount;
        $achieved = (float) $plan->achieved_amount;

        return [
            'target' => $target,
            'achieved' => $achieved,
            'pct' => $target > 0 ? min(100, round($achieved / $target * 100, 1)) : 0.0,
            'bonus' => $plan->bonusAmount(),
            'status' => $plan->status->value,
        ];
    }

    /**
     * ما حُسم على الموظف هذا الشهر، ومجمله، وآخر سببٍ حُسم لأجله.
     *
     * @return array{monthTotal: float, monthCount: int, total: float, lastReason: ?string, lastDate: ?string}
     */
    private function deductions(int $userId): array
    {
        $monthStart = Carbon::now()->startOfMonth();
        $monthEnd = Carbon::now()->endOfMonth();

        $month = EmployeeDeduction::query()
            ->where('user_id', $userId)
            ->deductedBetween($monthStart, $monthEnd);

        $latest = EmployeeDeduction::query()
            ->where('user_id', $userId)
            ->latest('deducted_at')
            ->first();

        return [
            'monthTotal' => round((float) (clone $month)->sum('amount'), 2),
            'monthCount' => (clone $month)->count(),
            'total' => round((float) EmployeeDeduction::query()->where('user_id', $userId)->sum('amount'), 2),
            'lastReason' => $latest?->reasonLabel(),
            'lastDate' => $latest?->deducted_at?->toDateString(),
        ];
    }

    /**
     * Five most-recent invoices across both tables, merged and sorted by
     * creation time.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentInvoices(?int $branchId, ?int $userId): array
    {
        $rows = collect();

        foreach (self::TABLES as $table) {
            $type = $table === 'product_invoices' ? 'product' : 'service';

            $records = $this->scoped($table, $branchId, $userId)
                ->leftJoin('customers', 'customers.id', '=', $table . '.customer_id')
                ->orderByDesc($table . '.created_at')
                ->limit(5)
                ->get([
                    $table . '.id as id',
                    $table . '.invoice_number as invoice_number',
                    $table . '.total_amount as total_amount',
                    $table . '.status as status',
                    $table . '.created_at as created_at',
                    'customers.full_name as customer_name',
                ]);

            foreach ($records as $r) {
                $rows->push([
                    'id' => (int) $r->id,
                    'type' => $type,
                    'invoiceNumber' => $r->invoice_number,
                    'customerName' => $r->customer_name,
                    'total' => (float) $r->total_amount,
                    'status' => $r->status,
                    'createdAt' => $r->created_at ? Carbon::parse($r->created_at)->toIso8601String() : null,
                ]);
            }
        }

        return $rows->sortByDesc('createdAt')->take(5)->values()->all();
    }

    /**
     * Top five services in the window by revenue, from paid service invoices.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topServices(?int $branchId, ?int $userId, Carbon $windowStart): array
    {
        return DB::table('service_invoice_lines')
            ->join('service_invoices', 'service_invoices.id', '=', 'service_invoice_lines.invoice_id')
            ->whereNull('service_invoices.deleted_at')
            ->where('service_invoices.status', InvoiceStatusEnum::PAID->value)
            ->where('service_invoices.paid_at', '>=', $windowStart)
            ->when($branchId, fn($q) => $q->where('service_invoices.branch_id', $branchId))
            ->when($userId, fn($q) => $q->where('service_invoices.user_id', $userId))
            ->groupBy('service_invoice_lines.service_name')
            ->orderByDesc(DB::raw('SUM(service_invoice_lines.subtotal)'))
            ->limit(5)
            ->get([
                'service_invoice_lines.service_name as name',
                DB::raw('COUNT(*) as line_count'),
                DB::raw('COALESCE(SUM(service_invoice_lines.subtotal), 0) as total'),
            ])
            ->map(fn($row) => [
                'name' => $row->name,
                'count' => (int) $row->line_count,
                'total' => (float) $row->total,
            ])
            ->all();
    }
}
