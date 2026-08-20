<?php

namespace App\Actions\Customer;

use App\Enums\InvoiceStatusEnum;
use App\Models\Customer;
use App\Models\ProductInvoice;
use App\Models\ProductInvoiceLine;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

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

    /** Invoice model => spend-trend series key. */
    private const INVOICE_TYPES = [
        ServiceInvoice::class => 'service',
        ProductInvoice::class => 'product',
    ];

    /** @return array<string, mixed> */
    public function handle(Customer $customer): array
    {
        return [
            'monthlySpend' => $this->monthlySpend($customer),
            'kpis' => $this->kpis($customer),
            'topServices' => $this->topItems($customer, ServiceInvoiceLine::class, 'service_name'),
            'topProducts' => $this->topItems($customer, ProductInvoiceLine::class, 'product_name'),
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
            // now() is CarbonImmutable (Date::use in AppServiceProvider), so
            // addMonth() returns a new instance — reassign or loop forever.
            $cursor = $cursor->addMonth();
        }

        foreach (self::INVOICE_TYPES as $model => $type) {
            $rows = $model::query()
                ->whereBelongsTo($customer)
                ->where('status', InvoiceStatusEnum::PAID)
                ->where('created_at', '>=', $start)
                ->groupByRaw('DATE(created_at)')
                ->selectRaw('DATE(created_at) as day, COALESCE(SUM(total_amount), 0) as total')
                ->get();

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

        foreach (array_keys(self::INVOICE_TYPES) as $model) {
            $row = $model::query()
                ->whereBelongsTo($customer)
                ->where('status', InvoiceStatusEnum::PAID)
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
     * @param  class-string<ServiceInvoiceLine|ProductInvoiceLine>  $lineModel
     * @return array<int, array{name: string, qty: int, total: float}>
     */
    private function topItems(Customer $customer, string $lineModel, string $nameColumn): array
    {
        return $lineModel::query()
            ->whereHas('invoice', fn (Builder $query) => $query
                ->whereBelongsTo($customer)
                ->where('status', '<>', InvoiceStatusEnum::CANCELLED))
            ->groupBy($nameColumn)
            ->orderByDesc('total')
            ->limit(self::TOP_ITEMS)
            ->selectRaw("{$nameColumn} as name, COALESCE(SUM(qty), 0) as qty, COALESCE(SUM(subtotal), 0) as total")
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                // كمية عشرية: سطر المنتج المسعّر بالمتر يُجمع بمساحته (تاسك 51).
                'qty' => round((float) $row->qty, 2),
                'total' => (float) $row->total,
            ])
            ->all();
    }
}
