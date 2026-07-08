<?php

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Unified CRM timeline for one customer (M23): audit-log entries (profile
 * created/updated), invoices, loyalty point movements and refunds, merged
 * into a single reverse-chronological feed. Read-only — each source is
 * capped so a heavy customer cannot flood the page.
 */
class BuildCustomerTimelineAction
{
    private const PER_SOURCE_LIMIT = 50;

    private const TOTAL_LIMIT = 100;

    /** @return array<int, array<string, mixed>> */
    public function handle(Customer $customer): array
    {
        $events = array_merge(
            $this->auditEvents($customer),
            $this->invoiceEvents($customer),
            $this->loyaltyEvents($customer),
            $this->refundEvents($customer),
        );

        usort($events, fn (array $a, array $b) => strcmp($b['occurredAt'], $a['occurredAt']));

        return array_slice($events, 0, self::TOTAL_LIMIT);
    }

    /** @return array<int, array<string, mixed>> */
    private function auditEvents(Customer $customer): array
    {
        return Activity::query()
            ->where('subject_type', $customer->getMorphClass())
            ->where('subject_id', $customer->id)
            ->with('causer')
            ->latest()
            ->limit(self::PER_SOURCE_LIMIT)
            ->get()
            ->map(fn (Activity $activity) => [
                'id' => 'audit-'.$activity->id,
                'kind' => 'audit',
                'event' => $activity->event,
                'causer' => $activity->causer?->name,
                'changedFields' => array_keys($activity->properties['attributes'] ?? []),
                'occurredAt' => $activity->created_at->toISOString(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function invoiceEvents(Customer $customer): array
    {
        $events = [];

        foreach (['service_invoices' => 'service', 'product_invoices' => 'product'] as $table => $type) {
            $rows = DB::table($table)
                ->where('customer_id', $customer->id)
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->limit(self::PER_SOURCE_LIMIT)
                ->get(['id', 'invoice_number', 'total_amount', 'status', 'created_at', 'paid_at']);

            foreach ($rows as $row) {
                $events[] = [
                    'id' => "invoice-{$type}-{$row->id}",
                    'kind' => 'invoice',
                    'invoiceId' => $row->id,
                    'invoiceType' => $type,
                    'invoiceNumber' => $row->invoice_number,
                    'amount' => (float) $row->total_amount,
                    'status' => $row->status,
                    'occurredAt' => Carbon::parse($row->created_at)->toISOString(),
                ];
            }
        }

        return $events;
    }

    /** @return array<int, array<string, mixed>> */
    private function loyaltyEvents(Customer $customer): array
    {
        return DB::table('loyalty_transactions')
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->limit(self::PER_SOURCE_LIMIT)
            ->get(['id', 'type', 'points', 'balance_after', 'notes', 'created_at'])
            ->map(fn (object $row) => [
                'id' => 'loyalty-'.$row->id,
                'kind' => 'loyalty',
                'loyaltyType' => $row->type,
                'points' => (int) $row->points,
                'balanceAfter' => (int) $row->balance_after,
                'notes' => $row->notes,
                'occurredAt' => Carbon::parse($row->created_at)->toISOString(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function refundEvents(Customer $customer): array
    {
        $scopes = [
            (new ServiceInvoice)->getMorphClass() => 'service_invoices',
            (new ProductInvoice)->getMorphClass() => 'product_invoices',
        ];

        $events = [];

        foreach ($scopes as $morphClass => $table) {
            $rows = DB::table('refunds')
                ->where('invoice_type', $morphClass)
                ->whereNull('deleted_at')
                ->whereIn('invoice_id', fn ($q) => $q->select('id')->from($table)
                    ->where('customer_id', $customer->id))
                ->orderByDesc('created_at')
                ->limit(self::PER_SOURCE_LIMIT)
                ->get(['id', 'amount', 'reason', 'stock_reversed', 'created_at']);

            foreach ($rows as $row) {
                $events[] = [
                    'id' => 'refund-'.$row->id,
                    'kind' => 'refund',
                    'amount' => (float) $row->amount,
                    'reason' => $row->reason,
                    'occurredAt' => Carbon::parse($row->created_at)->toISOString(),
                ];
            }
        }

        return $events;
    }
}
