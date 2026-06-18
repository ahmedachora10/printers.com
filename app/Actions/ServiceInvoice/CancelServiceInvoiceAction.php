<?php

namespace App\Actions\ServiceInvoice;

use App\Enums\CommissionSourceTypeEnum;
use App\Enums\InvoiceStatusEnum;
use App\Enums\LoyaltyTransactionTypeEnum;
use App\Models\CommissionLedger;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
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

            $this->reverseCommission($invoice);
            $this->restoreRedeemedPoints($invoice);

            return $invoice;
        });
    }

    /**
     * Reverse every unpaid commission row for the invoice's lines in full by
     * inserting a negative offsetting row. Paid commission is left untouched.
     */
    private function reverseCommission(ServiceInvoice $invoice): void
    {
        $lineIds = $invoice->lines()->pluck('id');

        if ($lineIds->isEmpty()) {
            return;
        }

        $entries = CommissionLedger::query()
            ->where('invoice_line_type', ServiceInvoiceLine::class)
            ->whereIn('invoice_line_id', $lineIds)
            ->whereNull('paid_at')
            ->where('amount', '>', 0)
            ->lockForUpdate()
            ->get();

        foreach ($entries as $entry) {
            CommissionLedger::create([
                'user_id' => $entry->user_id,
                'branch_id' => $entry->branch_id,
                'invoice_line_id' => $entry->invoice_line_id,
                'invoice_line_type' => $entry->invoice_line_type,
                'amount' => -1 * (float) $entry->amount,
                'is_tahazir' => $entry->is_tahazir,
                'tier_applied' => $entry->tier_applied,
                'source_type' => CommissionSourceTypeEnum::STANDARD,
                'earned_at' => now(),
            ]);
        }
    }

    /**
     * Return points the customer redeemed on this invoice. Redemption ran at
     * creation regardless of status, so a cancelled (never-paid) invoice must
     * give them back via a positive manual adjustment.
     */
    private function restoreRedeemedPoints(ServiceInvoice $invoice): void
    {
        $points = (int) $invoice->points_redeemed;

        if ($points <= 0 || $invoice->customer_id === null) {
            return;
        }

        /** @var Customer|null $customer */
        $customer = Customer::query()
            ->whereKey($invoice->customer_id)
            ->lockForUpdate()
            ->first();

        if (! $customer) {
            return;
        }

        $newBalance = $customer->points_balance + $points;
        $customer->update(['points_balance' => $newBalance]);

        LoyaltyTransaction::create([
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'invoice_type' => $invoice->getMorphClass(),
            'type' => LoyaltyTransactionTypeEnum::ManualAdjust,
            'points' => $points,
            'balance_after' => $newBalance,
            'notes' => "استرجاع نقاط بعد إلغاء الفاتورة {$invoice->invoice_number}",
        ]);
    }
}
