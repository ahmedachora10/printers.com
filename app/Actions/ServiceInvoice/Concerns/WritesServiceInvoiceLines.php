<?php

namespace App\Actions\ServiceInvoice\Concerns;

use App\Enums\CommissionSourceTypeEnum;
use App\Models\CommissionLedger;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;

/**
 * Persists calculated line rows and their immutable commission-ledger entries
 * for a service invoice. Shared by create and edit so a line always earns an
 * identically-shaped ledger row. Call inside the surrounding transaction.
 */
trait WritesServiceInvoiceLines
{
    /**
     * @param  list<array<string, mixed>>  $lines  rows from CalculateServiceInvoiceAction
     */
    protected function writeLinesAndLedger(ServiceInvoice $invoice, array $lines, int $userId, int $branchId): void
    {
        foreach ($lines as $line) {
            /** @var ServiceInvoiceLine $invoiceLine */
            $invoiceLine = $invoice->lines()->create([
                'branch_service_id' => $line['branch_service_id'],
                'service_name' => $line['service_name'],
                'qty' => $line['qty'],
                'unit_price' => $line['unit_price'],
                'discount_pct' => $line['discount_pct'],
                'subtotal' => $line['subtotal'],
                'commission_pct' => $line['commission_pct'],
                'commission_amount' => $line['commission_amount'],
            ]);

            // One immutable ledger row per line. Tier resolution will populate
            // tier_applied once commission tiers are built (M07/M15).
            CommissionLedger::create([
                'user_id' => $userId,
                'branch_id' => $branchId,
                'invoice_line_id' => $invoiceLine->id,
                'invoice_line_type' => ServiceInvoiceLine::class,
                'amount' => $line['commission_amount'],
                'is_tahazir' => $line['is_tahazir'],
                'tier_applied' => null,
                'source_type' => CommissionSourceTypeEnum::STANDARD,
                'earned_at' => now(),
            ]);
        }
    }
}
