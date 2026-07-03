<?php

namespace App\Actions\ServiceInvoice;

use App\Actions\ServiceInvoice\Concerns\ReversesServiceInvoiceAccruals;
use App\Enums\InvoiceStatusEnum;
use App\Models\ServiceInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Soft-deletes a service invoice raised by an employee and unwinds every
 * accrual tied to it, whether it was still DUE or already PAID:
 *  - unpaid commission is reversed via negative ledger rows;
 *  - points the customer redeemed on it are restored;
 *  - points earned on it (paid invoices only) are clawed back;
 *  - the coupon capacity it consumed is released.
 *
 * A paid invoice that has already been refunded or rolled into an agent
 * payment is refused — those immutable records would be left dangling.
 */
class DeleteServiceInvoiceAction
{
    use ReversesServiceInvoiceAccruals;

    public function handle(ServiceInvoice $invoice): ServiceInvoice
    {
        if ($invoice->status === InvoiceStatusEnum::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => 'الفاتورة ملغاة بالفعل.',
            ]);
        }

        if ($invoice->refunds()->exists()) {
            throw ValidationException::withMessages([
                'invoice' => 'لا يمكن حذف فاتورة لها مرتجعات. عالج المرتجع أولاً.',
            ]);
        }

        if ($invoice->agent_payment_id !== null) {
            throw ValidationException::withMessages([
                'invoice' => 'لا يمكن حذف فاتورة مُدرجة ضمن دفعة وكيل.',
            ]);
        }

        return DB::transaction(function () use ($invoice) {
            $this->reverseUnpaidCommission($invoice);
            $this->restoreRedeemedPoints($invoice);
            $this->clawBackEarnedPoints($invoice);
            $this->releaseCoupon($invoice);

            $invoice->delete();

            return $invoice;
        });
    }
}
