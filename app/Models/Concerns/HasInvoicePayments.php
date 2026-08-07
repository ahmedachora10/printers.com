<?php

namespace App\Models\Concerns;

use App\Models\InvoicePayment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Shared payment-schedule handling for the two invoice models: an invoice may
 * be settled by a deposit (عربون) plus later payments instead of one payment
 * at the till.
 *
 * The payment rows are the source of truth for what has actually been
 * collected; `total_amount` stays the agreed price of the invoice. An invoice
 * settled directly at the POS carries no payment rows at all — for it, the
 * collected amount is the whole total.
 */
trait HasInvoicePayments
{
    /** @return MorphMany<InvoicePayment, $this> */
    public function payments(): MorphMany
    {
        return $this->morphMany(InvoicePayment::class, 'invoice');
    }

    /**
     * ما حُصِّل فعلاً من الفاتورة. الدفعات المسجَّلة هي المرجع؛ فإن لم تكن هناك
     * دفعات فالفاتورة إما سُدِّدت كاملة عند البيع (الإجمالي) أو لم يُقبض منها شيء.
     */
    public function paidAmount(): float
    {
        $payments = $this->relationLoaded('payments')
            ? $this->payments
            : $this->payments()->get();

        if ($payments->isEmpty()) {
            return $this->status->isPaid() ? (float) $this->total_amount : 0.0;
        }

        return round((float) $payments->sum('amount'), 2);
    }

    /** المتبقي على العميل — لا ينزل تحت الصفر. */
    public function remainingAmount(): float
    {
        return round(max((float) $this->total_amount - $this->paidAmount(), 0), 2);
    }
}
