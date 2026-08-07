<?php

namespace App\Actions\ServiceInvoice;

use App\Actions\Loyalty\EarnLoyaltyPointsAction;
use App\Actions\ServiceInvoice\Concerns\WritesServiceInvoiceLines;
use App\Enums\InvoiceStatusEnum;
use App\Models\ServiceInvoice;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Settles a service invoice: marks it paid, stamps paid_at, writes the
 * employee's commission ledger, and credits loyalty points. Both commission and
 * points are only realised once the invoice becomes paid (approved).
 *
 * This is the single approval path. It is reached either by an accountant
 * approving the whole invoice from the review queue, or by the final instalment
 * completing an invoice that was being paid off (عربون + دفعات لاحقة) — in which
 * case RecordInvoicePaymentAction calls it with that last payment's moment as
 * $paidAt. Only an invoice that still awaits money (due or partially paid) can
 * be settled here, so the ledger and the points are never written twice.
 */
class MarkServiceInvoicePaidAction
{
    use WritesServiceInvoiceLines;

    public function __construct(
        private readonly EarnLoyaltyPointsAction $earnLoyaltyPoints,
    ) {}

    public function handle(ServiceInvoice $invoice, ?CarbonInterface $paidAt = null): ServiceInvoice
    {
        if (! $invoice->status->acceptsPayment()) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن اعتماد الدفع إلا لفاتورة آجلة أو مدفوعة جزئياً.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $paidAt) {
            $invoice->update([
                'status' => InvoiceStatusEnum::PAID,
                // فاتورة سُدِّدت على دفعات: تاريخ السداد هو لحظة آخر دفعة، لا لحظة الاعتماد.
                'paid_at' => $paidAt ?? now(),
            ]);

            // The invoice is now approved, so the employee earns their commission:
            // write the immutable ledger rows from the persisted lines.
            $this->writeLedgerFromLines($invoice, (int) $invoice->user_id, (int) $invoice->branch_id);

            // Credit points now that the invoice is paid; no-ops for ineligible
            // customers (corporate, agent-linked, or inactive loyalty config).
            $this->earnLoyaltyPoints->handle($invoice);

            return $invoice;
        });
    }
}
