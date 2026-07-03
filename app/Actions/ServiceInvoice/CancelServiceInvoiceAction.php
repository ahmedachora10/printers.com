<?php

namespace App\Actions\ServiceInvoice;

use App\Actions\ServiceInvoice\Concerns\ReversesServiceInvoiceAccruals;
use App\Enums\InvoiceStatusEnum;
use App\Models\ServiceInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cancels a due service invoice. Because the invoice was never paid, all
 * accruals tied to it are unwound atomically:
 *  - the employee's *unpaid* commission is reversed in full via negative
 *    offsetting rows (the commission ledger is immutable);
 *  - any points the customer redeemed on the invoice are restored.
 *
 * Only a due invoice can be cancelled here. Settled (paid) invoices are
 * reversed through the refunds module instead.
 */
class CancelServiceInvoiceAction
{
    use ReversesServiceInvoiceAccruals;

    public function handle(ServiceInvoice $invoice, string $reason): ServiceInvoice
    {
        if ($invoice->status !== InvoiceStatusEnum::DUE) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن إلغاء إلا فاتورة آجلة.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $reason) {
            $invoice->update([
                'status' => InvoiceStatusEnum::CANCELLED,
                'cancellation_reason' => $reason,
            ]);

            $this->reverseUnpaidCommission($invoice);
            $this->restoreRedeemedPoints($invoice);

            return $invoice;
        });
    }
}
