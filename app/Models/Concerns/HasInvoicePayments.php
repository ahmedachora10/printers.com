<?php

namespace App\Models\Concerns;

use App\Enums\InvoiceStatusEnum;
use App\Models\InvoicePayment;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Validation\ValidationException;

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

    /**
     * ما يقع عليه الخصم الإضافي: الإجمالي قبله، بلا التوصيل — الشحن خارج كل
     * خصم (تاسك 93). فاتورة المنتجات بلا عمود شحن، فيُقرأ صفراً.
     */
    public function discountBase(): float
    {
        return round((float) $this->total_amount - (float) ($this->shipping_fee ?? 0) + (float) $this->manual_discount, 2);
    }

    /**
     * بعد تعديل فاتورةٍ معتمدة، والإجمالي الجديد محفوظ: التحصيل يتبعه. المسدَّدة
     * عند البيع بلا صفوف دفعات محصَّلُها إجماليُّها من تلقاء نفسه، والمسدَّدة
     * بدفعات تُكتب لها دفعة تسوية بالفرق (موجبة أو سالبة) ولا تُمسّ دفعاتها
     * السابقة. والمدفوعة جزئياً لا ينزل إجماليها تحت ما حُصِّل، فإن ساواه اكتمل
     * سدادها — والمتصل يقرأ الحالة بعدها ليكتب أثر الاعتماد.
     */
    public function settleAfterEdit(int $actorId): void
    {
        if (! $this->payments()->exists()) {
            return;
        }

        $total = round((float) $this->total_amount, 2);
        $collected = round((float) $this->payments()->sum('amount'), 2);

        if ($this->status === InvoiceStatusEnum::PARTIALLY_PAID) {
            if ($total < $collected) {
                throw ValidationException::withMessages([
                    'lines' => 'الإجمالي الجديد أقل مما حُصِّل من الفاتورة ('.number_format($collected, 2).' ر.س).',
                ]);
            }

            if ($total === $collected) {
                $this->update(['status' => InvoiceStatusEnum::PAID, 'paid_at' => $this->payments()->max('paid_at')]);
            }
        } elseif ($this->status === InvoiceStatusEnum::PAID && $total !== $collected) {
            $this->payments()->create([
                'branch_id' => $this->branch_id,
                'payment_method_id' => $this->payment_method_id,
                'amount' => round($total - $collected, 2),
                'paid_at' => now(),
                'recorded_by' => $actorId,
                'notes' => 'تسوية بعد تعديل الفاتورة',
            ]);
        }
    }

    /** المتبقي على العميل — لا ينزل تحت الصفر. */
    public function remainingAmount(): float
    {
        return round(max((float) $this->total_amount - $this->paidAmount(), 0), 2);
    }
}
