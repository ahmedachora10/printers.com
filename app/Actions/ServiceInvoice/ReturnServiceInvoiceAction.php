<?php

namespace App\Actions\ServiceInvoice;

use App\Actions\Refund\CreateRefundAction;
use App\Actions\ServiceInvoice\Concerns\ReversesServiceInvoiceAccruals;
use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Returns (استرجاع) a service invoice raised by an employee — the operation that
 * replaced deleting it. The invoice row is never removed: it stays visible with
 * status RETURNED, and every accrual tied to it is unwound atomically:
 *  - a settled invoice books its outstanding amount as an M14 refund, which also
 *    reverses the employee's unpaid commission (negative ledger rows only);
 *  - a due invoice books no refund — no money was ever collected — so its unpaid
 *    commission is reversed directly;
 *  - points the customer redeemed on it are restored, points it earned are
 *    clawed back, and the coupon capacity it consumed is released.
 *
 * An invoice already rolled into an agent payment is refused: that record is
 * immutable and would be left dangling.
 */
class ReturnServiceInvoiceAction
{
    use ReversesServiceInvoiceAccruals;

    public function __construct(
        private readonly CreateRefundAction $createRefund,
    ) {}

    public function handle(ServiceInvoice $invoice, User $actor, ?string $reason = null): ServiceInvoice
    {
        if ($invoice->status === InvoiceStatusEnum::RETURNED) {
            throw ValidationException::withMessages([
                'status' => 'الفاتورة مُرتجعة بالفعل.',
            ]);
        }

        if ($invoice->status === InvoiceStatusEnum::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن استرجاع فاتورة ملغاة.',
            ]);
        }

        if ($invoice->invoiceAgents()->whereNotNull('agent_payment_id')->exists()) {
            throw ValidationException::withMessages([
                'invoice' => 'لا يمكن استرجاع فاتورة مُدرجة ضمن دفعة مندوب.',
            ]);
        }

        return DB::transaction(function () use ($invoice, $actor, $reason) {
            $wasSettled = $invoice->status === InvoiceStatusEnum::PAID;
            $refundable = $this->refundableRemaining($invoice);

            if ($wasSettled && $refundable > 0) {
                // The refund is what reverses the unpaid commission here (pro rata
                // over what is left to refund), so it must not be reversed again.
                $this->createRefund->handle([
                    'source_type' => InvoiceTypeEnum::SERVICE->value,
                    'invoice_id' => $invoice->id,
                    'amount' => $refundable,
                    'reason' => $reason ?: "استرجاع الفاتورة {$invoice->invoice_number}",
                ], $actor);
            } elseif (! $wasSettled) {
                $this->reverseUnpaidCommission($invoice);
            }

            $this->restoreRedeemedPoints($invoice);
            $this->clawBackEarnedPoints($invoice);
            $this->releaseCoupon($invoice);

            $invoice->update(['status' => InvoiceStatusEnum::RETURNED]);

            return $invoice;
        });
    }

    /**
     * What is still refundable on the invoice — its total less any partial
     * refunds already booked against it.
     */
    private function refundableRemaining(ServiceInvoice $invoice): float
    {
        $alreadyRefunded = (float) Refund::query()
            ->where('invoice_type', $invoice->getMorphClass())
            ->where('invoice_id', $invoice->id)
            ->sum('amount');

        return max(0, round((float) $invoice->total_amount - $alreadyRefunded, 2));
    }
}
