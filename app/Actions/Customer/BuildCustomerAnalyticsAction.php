<?php

namespace App\Actions\Customer;

use App\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-customer CRM analytics (M23): 12-month spend trend, purchase KPIs and
 * top purchased services/products. Revenue figures only count non-cancelled
 * invoices; "realized" KPIs (lifetime spend, average invoice) count paid ones,
 * matching the sales-report definition of realized revenue.
 */
class BuildCustomerAnalyticsAction
{
    private const MONTHS = 12;

    private const TOP_ITEMS = 5;

    /** @return array<string, mixed> */
    public function handle(Customer $customer): array
    {
        return [
            'monthlySpend' => $this->monthlySpend($customer),
            'kpis' => $this->kpis($customer),
            'topServices' => $this->topItems(
                $customer, 'service_invoice_lines', 'service_invoices', 'service_name'
            ),
            'topProducts' => $this->topItems(
                $customer, 'product_invoice_lines', 'product_invoices', 'product_name'
            ),
        ];
    }

    /**
     * Paid spend per month for the trailing 12 months, split by invoice type
     * and zero-filled so the chart is continuous. Grouped per day in SQL
     * (portable across MySQL/SQLite), bucketed into months here — same
     * approach as the M25 analytics dashboard.
     *
     * @return array<int, array{month: string, service: float, product: float}>
     */
    private function monthlySpend(Customer $customer): array
    {
        $start = now()->subMonths(self::MONTHS - 1)->startOfMonth();

        $months = [];
        $cursor = $start->copy();
        while ($cursor <= now()) {
            $months[$cursor->format('Y-m')] = ['month' => $cursor->format('Y-m'), 'service' => 0.0, 'product' => 0.0];
            $cursor->addMonth();
        }

        foreach (['service_invoices' => 'service', 'product_invoices' => 'product'] as $table => $type) {
            $rows = DB::table($table)
                ->where('customer_id', $customer->id)
                ->where('status', 'paid')
                ->whereNull('deleted_at')
                ->where('created_at', '>=', $start)
                ->groupBy(DB::raw('DATE(created_at)'))
                ->get([
                    DB::raw('DATE(created_at) as day'),
                    DB::raw('COALESCE(SUM(total_amount), 0) as total'),
                ]);

            foreach ($rows as $row) {
                $key = substr((string) $row->day, 0, 7);

                if (isset($months[$key])) {
                    $months[$key][$type] += (float) $row->total;
                }
            }
        }

        ksort($months);

        return array_values($months);
    }

    /** @return array<string, mixed> */
    private function kpis(Customer $customer): array
    {
        $paidCount = 0;
        $paidTotal = 0.0;
        $firstAt = null;
        $lastAt = null;

        foreach (['service_invoices', 'product_invoices'] as $table) {
            $row = DB::table($table)
                ->where('customer_id', $customer->id)
                ->where('status', 'paid')
                ->whereNull('deleted_at')
                ->selectRaw(
                    'COUNT(*) as cnt,
                    COALESCE(SUM(total_amount), 0) as total,
                    MIN(created_at) as first_at,
                    MAX(created_at) as last_at'
                )
                ->first();

            if ($row === null || (int) $row->cnt === 0) {
                continue;
            }

            $paidCount += (int) $row->cnt;
            $paidTotal += (float) $row->total;
            $firstAt = $firstAt === null ? $row->first_at : min($firstAt, $row->first_at);
            $lastAt = $lastAt === null ? $row->last_at : max($lastAt, $row->last_at);
        }

        $first = $firstAt !== null ? Carbon::parse($firstAt) : null;
        $last = $lastAt !== null ? Carbon::parse($lastAt) : null;

        // Purchase cadence over the customer's active lifetime (first paid
        // invoice → now), floored at one month so new customers don't spike.
        $activeMonths = $first !== null ? max(1, (int) ceil($first->diffInDays(now()) / 30)) : 1;

        return [
            'paidInvoiceCount' => $paidCount,
            'lifetimeSpend' => round($paidTotal, 2),
            'avgInvoiceValue' => $paidCount > 0 ? round($paidTotal / $paidCount, 2) : 0.0,
            'purchasesPerMonth' => round($paidCount / $activeMonths, 1),
            'firstPurchaseAt' => $first?->toISOString(),
            'lastPurchaseAt' => $last?->toISOString(),
            'daysSinceLastPurchase' => $last !== null ? (int) $last->diffInDays(now()) : null,
        ];
    }

    /**
     * Top purchased line items by realized value across non-cancelled invoices.
     *
     * @return array<int, array{name: string, qty: int, total: float}>
     */
    private function topItems(Customer $customer, string $linesTable, string $invoicesTable, string $nameColumn): array
    {
        return DB::table($linesTable)
            ->join($invoicesTable, "{$invoicesTable}.id", '=', "{$linesTable}.invoice_id")
            ->where("{$invoicesTable}.customer_id", $customer->id)
            ->where("{$invoicesTable}.status", '<>', 'cancelled')
            ->whereNull("{$invoicesTable}.deleted_at")
            ->groupBy("{$linesTable}.{$nameColumn}")
            ->orderByDesc('total')
            ->limit(self::TOP_ITEMS)
            ->get([
                DB::raw("{$linesTable}.{$nameColumn} as name"),
                DB::raw("COALESCE(SUM({$linesTable}.qty), 0) as qty"),
                DB::raw("COALESCE(SUM({$linesTable}.subtotal), 0) as total"),
            ])
            ->map(fn (object $row) => [
                'name' => $row->name,
                'qty' => (int) $row->qty,
                'total' => (float) $row->total,
            ])
            ->all();
    }
}
