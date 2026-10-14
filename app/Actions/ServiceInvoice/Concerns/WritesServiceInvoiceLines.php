<?php

namespace App\Actions\ServiceInvoice\Concerns;

use App\Enums\CommissionSourceTypeEnum;
use App\Models\CommissionLedger;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceHelper;
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
                'notes' => $line['notes'] ?? null,
                'qty' => $line['qty'],
                'unit_price' => $line['unit_price'],
                'width_cm' => $line['width_cm'] ?? null,
                'height_cm' => $line['height_cm'] ?? null,
                // ما يقيسه unit_price: سعر متر لسطر مسعّر بالمتر، وسعر وحدة لغيره.
                'unit_price_basis' => $line['unit_price_basis'] ?? null,
                'discount_pct' => $line['discount_pct'],
                'subtotal' => $line['subtotal'],
                'commission_pct' => $line['commission_pct'],
                'commission_amount' => $line['commission_amount'],
                'materials_cost' => $line['materials_cost'] ?? 0,
                'materials_total' => $line['materials_total'] ?? 0,
                'is_tahazir' => $line['is_tahazir'],
                'agent_id' => $line['agent_id'] ?? null,
                'agent_commission_type' => $line['agent_commission_type'] ?? null,
                'agent_commission_value' => $line['agent_commission_value'] ?? null,
                'agent_commission_amount' => $line['agent_commission_amount'] ?? 0,
            ]);
        }
    }

    /**
     * المساعدون كما حسبتهم الحاسبة — لقطةٌ يُكتب منها صفّ عمولة كلٍّ منهم عند الاعتماد.
     *
     * @param  list<array<string, mixed>>  $helpers  rows from CalculateServiceInvoiceAction
     */
    protected function writeHelpers(ServiceInvoice $invoice, array $helpers): void
    {
        foreach ($helpers as $helper) {
            $invoice->helpers()->create($helper);
        }
    }

    /**
     * Write one immutable commission-ledger row per persisted line, and one per
     * helper (to the helper). Called when the invoice is (or becomes) paid, or
     * once a partial payment reaches its commissionFloor() (task 182) — never
     * for a due invoice.
     */
    protected function writeLedgerFromLines(ServiceInvoice $invoice, int $userId, int $branchId): void
    {
        // تاسك 166: العمولة تُستحق لحظة التحصيل — تاريخ الدفعة الأخيرة كما أدخله
        // المستخدم، لا لحظة إدخالها. تاسك 182: أو الدفعة التي بلغت الحدّ قبلها.
        $earnedAt = $invoice->commission_earned_at ?? $invoice->paid_at ?? now();

        foreach ($invoice->helpers()->get() as $helper) {
            /** @var ServiceInvoiceHelper $helper */
            CommissionLedger::create([
                'user_id' => $helper->user_id,
                'branch_id' => $branchId,
                'invoice_line_id' => $helper->id,
                'invoice_line_type' => ServiceInvoiceHelper::class,
                'amount' => $helper->commission_amount,
                'is_tahazir' => $helper->is_tahazir,
                'tier_applied' => null,
                'source_type' => CommissionSourceTypeEnum::STANDARD,
                'earned_at' => $earnedAt,
            ]);
        }

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
                'earned_at' => $earnedAt,
            ]);
        }
    }
}
