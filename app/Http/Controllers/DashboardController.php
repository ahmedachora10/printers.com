<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatusEnum;
use App\Enums\Roles;
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

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $role = $user->roleName;

        // Agents have their own portal; the dashboard has nothing for them.
        if ($role === Roles::AGENT) {
            return redirect()->route('agent-portal.index');
        }

        $isSuper = $role?->isSuperAdmin() ?? false;
        $isEmployee = $role?->isEmployee() ?? false;

        // Super-admin sees every branch; everyone else is pinned to their own.
        $branchId = $isSuper ? null : $user->branchId;
        // Employees only ever see their own sales, dues and commissions.
        $userId = $isEmployee ? $user->id : null;

        $today = Carbon::today();
        $monthStart = Carbon::now()->startOfMonth();

        return Inertia::render('dashboard', [
            'kpis' => [
                'todaySales' => $this->paidSalesBetween($branchId, $userId, $today->copy()->startOfDay(), $today->copy()->endOfDay()),
                'monthSales' => $this->paidSalesBetween($branchId, $userId, $monthStart, Carbon::now()),
                'outstandingDue' => $this->outstandingDue($branchId, $userId),
                'pendingCommissions' => $this->pendingCommissions($branchId, $userId),
                // Inventory is a manager concern; hidden for accountants/employees.
                'lowStockCount' => $isSuper || $role === Roles::BRANCH_ADMIN ? $this->lowStockCount($branchId) : null,
            ],
            'recentInvoices' => $this->recentInvoices($branchId, $userId),
            'topServices' => $this->topServices($branchId, $userId, $monthStart),
            'scope' => [
                'isSuper' => $isSuper,
                'isEmployee' => $isEmployee,
                'userName' => $user->name,
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
            ->whereNull($table.'.deleted_at')
            ->when($branchId, fn ($q) => $q->where($table.'.branch_id', $branchId))
            ->when($userId, fn ($q) => $q->where($table.'.user_id', $userId));
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
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->sum('amount');
    }

    /** Active products at or below their minimum stock level. */
    private function lowStockCount(?int $branchId): int
    {
        return Product::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereColumn('current_stock', '<=', 'min_stock_level')
            ->where('is_active', true)
            ->count();
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
                ->leftJoin('customers', 'customers.id', '=', $table.'.customer_id')
                ->orderByDesc($table.'.created_at')
                ->limit(5)
                ->get([
                    $table.'.id as id',
                    $table.'.invoice_number as invoice_number',
                    $table.'.total_amount as total_amount',
                    $table.'.status as status',
                    $table.'.created_at as created_at',
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
     * Top five services this month by revenue, from paid service invoices.
     *
     * @return array<int, array<string, mixed>>
     */
    private function topServices(?int $branchId, ?int $userId, Carbon $monthStart): array
    {
        return DB::table('service_invoice_lines')
            ->join('service_invoices', 'service_invoices.id', '=', 'service_invoice_lines.invoice_id')
            ->whereNull('service_invoices.deleted_at')
            ->where('service_invoices.status', InvoiceStatusEnum::PAID->value)
            ->where('service_invoices.paid_at', '>=', $monthStart)
            ->when($branchId, fn ($q) => $q->where('service_invoices.branch_id', $branchId))
            ->when($userId, fn ($q) => $q->where('service_invoices.user_id', $userId))
            ->groupBy('service_invoice_lines.service_name')
            ->orderByDesc(DB::raw('SUM(service_invoice_lines.subtotal)'))
            ->limit(5)
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
}
