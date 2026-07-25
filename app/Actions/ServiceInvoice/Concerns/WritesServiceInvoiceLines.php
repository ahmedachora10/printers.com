<?php

namespace App\Actions\ServiceInvoice\Concerns;

use App\Enums\CommissionSourceTypeEnum;
use App\Models\CommissionLedger;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;

/**
 * Persists a service invoice's calculated line rows and, once the invoice is
 * approved (paid), their immutable commission-ledger entries. Shared by create
 * and edit so a line is always shaped identically. Call inside the surrounding
 * transaction.
 *
 * Line rows are written up front (they exist the moment the invoice does), but
 * the commission ledger is deferred until the invoice is paid — an employee's
 * commission is only earned once the accountant approves the invoice, mirroring
 * how loyalty points are credited only on paid invoices. So a due invoice never
 * writes a ledger row (nothing to reverse if it is later cancelled), and payment
 * writes the ledger from the persisted lines.
 */
trait WritesServiceInvoiceLines
{
    /**
     * Persist the invoice's line rows (no ledger). The tahazir flag is snapshotted
     * onto the line so the ledger can be written faithfully later, at payment time.
     *
     * @param  list<array<string, mixed>>  $lines  rows from CalculateServiceInvoiceAction
     */
    protected function writeLines(ServiceInvoice $invoice, array $lines): void
    {
        foreach ($lines as $line) {
            $invoice->lines()->create([
                'branch_service_id' => $line['branch_service_id'],
                'service_name' => $line['service_name'],
                'qty' => $line['qty'],
                'unit_price' => $line['unit_price'],
                'width_cm' => $line['width_cm'] ?? null,
                'height_cm' => $line['height_cm'] ?? null,
                'discount_pct' => $line['discount_pct'],
                'subtotal' => $line['subtotal'],
                'commission_pct' => $line['commission_pct'],
                'commission_amount' => $line['commission_amount'],
                'is_tahazir' => $line['is_tahazir'],
                'agent_id' => $line['agent_id'] ?? null,
                'agent_commission_type' => $line['agent_commission_type'] ?? null,
                'agent_commission_value' => $line['agent_commission_value'] ?? null,
                'agent_commission_amount' => $line['agent_commission_amount'] ?? 0,
            ]);
        }
    }

    /**
     * Write one immutable commission-ledger row per persisted line. Called only
     * when the invoice is (or becomes) paid — never for a due invoice.
     */
    protected function writeLedgerFromLines(ServiceInvoice $invoice, int $userId, int $branchId): void
    {
        foreach ($invoice->lines()->get() as $line) {
            /** @var ServiceInvoiceLine $line */
            // Tier resolution will populate tier_applied once commission tiers are
            // built (M07/M15).
            CommissionLedger::create([
                'user_id' => $userId,
                'branch_id' => $branchId,
                'invoice_line_id' => $line->id,
                'invoice_line_type' => ServiceInvoiceLine::class,
                'amount' => $line->commission_amount,
                'is_tahazir' => $line->is_tahazir,
                'tier_applied' => null,
                'source_type' => CommissionSourceTypeEnum::STANDARD,
                'earned_at' => now(),
            ]);
        }
    }
}
