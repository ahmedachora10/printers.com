<?php

namespace App\Actions\Customer;

use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\ProductInvoice;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Unified CRM timeline for one customer (M23): audit-log entries (profile
 * created/updated), invoices, loyalty point movements and refunds, merged
 * into a single reverse-chronological feed. Read-only — each source is
 * capped so a heavy customer cannot flood the page.
 *
 * Expects the caller to eager-load `productInvoices`, `serviceInvoices`,
 * `activities` (with `causer`) and `loyaltyTransactions`, each capped at
 * PER_SOURCE_LIMIT and ordered latest-first.
 */
class BuildCustomerTimelineAction
{
    public const PER_SOURCE_LIMIT = 50;

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
        return $customer->activities
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
        return $customer->serviceInvoices
            ->map(fn (ServiceInvoice $invoice) => $this->buildInvoiceEvent($invoice, 'service'))
            ->merge($customer->productInvoices
                ->map(fn (ProductInvoice $invoice) => $this->buildInvoiceEvent($invoice, 'product')))
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function loyaltyEvents(Customer $customer): array
    {
        return $customer->loyaltyTransactions
            ->map(fn (LoyaltyTransaction $transaction) => [
                'id' => 'loyalty-'.$transaction->id,
                'kind' => 'loyalty',
                'loyaltyType' => $transaction->type->value,
                'points' => $transaction->points,
                'balanceAfter' => $transaction->balance_after,
                'notes' => $transaction->notes,
                'occurredAt' => $transaction->created_at->toISOString(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function refundEvents(Customer $customer): array
    {
        return Refund::query()
            ->whereHasMorph(
                'invoice',
                [ServiceInvoice::class, ProductInvoice::class],
                fn (Builder $query) => $query->where('customer_id', $customer->id),
            )
            ->latest()
            ->limit(self::PER_SOURCE_LIMIT)
            ->get()
            ->map(fn (Refund $refund) => [
                'id' => 'refund-'.$refund->id,
                'kind' => 'refund',
                'amount' => (float) $refund->amount,
                'reason' => $refund->reason,
                'occurredAt' => $refund->created_at->toISOString(),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function buildInvoiceEvent(ProductInvoice|ServiceInvoice $invoice, string $type): array
    {
        return [
            'id' => "invoice-{$type}-{$invoice->id}",
            'kind' => 'invoice',
            'invoiceId' => $invoice->id,
            'invoiceType' => $type,
            'invoiceNumber' => $invoice->invoice_number,
            'amount' => (float) $invoice->total_amount,
            'status' => $invoice->status->value,
            'occurredAt' => $invoice->created_at->toISOString(),
        ];
    }
}
