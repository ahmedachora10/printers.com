<?php

namespace App\Actions\ServiceInvoice;

use App\Actions\Loyalty\RedeemLoyaltyPointsAction;
use App\Actions\ServiceInvoice\Concerns\ReversesServiceInvoiceAccruals;
use App\Actions\ServiceInvoice\Concerns\SyncsServiceInvoiceAgents;
use App\Actions\ServiceInvoice\Concerns\WritesServiceInvoiceLines;
use App\Enums\InvoiceStatusEnum;
use App\Models\Branch;
use App\Models\ServiceInvoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Re-edits a DUE service invoice in place (before an accountant approves it),
 * keeping the same invoice number and id. The invoice's existing accruals are
 * unwound first (unpaid commission reversed, redeemed points restored, coupon
 * released), then the whole invoice is recomputed from the submitted data and
 * re-persisted. Only a due invoice may be edited — settled invoices are locked.
 */
class UpdateServiceInvoiceAction
{
    use ReversesServiceInvoiceAccruals, SyncsServiceInvoiceAgents, WritesServiceInvoiceLines;

    public function __construct(
        private readonly CalculateServiceInvoiceAction $calculator,
        private readonly RedeemLoyaltyPointsAction $redeemLoyaltyPoints,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(ServiceInvoice $invoice, array $data, ?UploadedFile $receipt = null): ServiceInvoice
    {
        if ($invoice->status !== InvoiceStatusEnum::DUE) {
            throw ValidationException::withMessages([
                'status' => 'لا يمكن تعديل إلا فاتورة آجلة قبل اعتمادها.',
            ]);
        }

        // An agent rebate already rolled into a payment would be left dangling if
        // the invoice were recomputed underneath it.
        if ($invoice->invoiceAgents()->whereNotNull('agent_payment_id')->exists()) {
            throw ValidationException::withMessages([
                'invoice' => 'لا يمكن تعديل فاتورة مُدرجة ضمن دفعة وكيل.',
            ]);
        }

        $branchId = (int) $invoice->branch_id;
        $userId = (int) $invoice->user_id;
        $branch = Branch::findOrFail($branchId);
        $vatPct = (float) $branch->vat_rate_override;

        return DB::transaction(function () use ($invoice, $data, $receipt, $branchId, $userId, $vatPct) {
            // Unwind the current invoice before recomputing. Restoring the
            // redeemed points first means the recomputation sees the customer's
            // real balance, so an unchanged redemption nets to zero.
            $this->reverseUnpaidCommission($invoice);
            $this->restoreRedeemedPoints($invoice);
            $this->releaseCoupon($invoice);
            $invoice->lines()->delete();

            $calc = $this->calculator->handle($data, $invoice->user, $branchId, $vatPct);

            $invoice->update([
                'status' => InvoiceStatusEnum::DUE,
                'paid_at' => null,
                ...$calc['attributes'],
            ]);

            if ($receipt !== null) {
                $invoice->clearMediaCollection(ServiceInvoice::RECEIPT_COLLECTION);
                $invoice->addMedia($receipt)->toMediaCollection(ServiceInvoice::RECEIPT_COLLECTION);
            }

            // The invoice stays due here, so no commission ledger is written; it is
            // deferred until the accountant approves (pays) the invoice.
            $this->writeLines($invoice, $calc['lines']);
            $this->syncInvoiceAgents($invoice, $calc['agents']);

            if ($calc['coupon']) {
                $calc['coupon']->increment('used_count');
            }

            if ($calc['pointsRedeemed'] > 0 && $invoice->customer_id !== null) {
                $this->redeemLoyaltyPoints->handle($invoice, $invoice->customer_id, $calc['pointsRedeemed']);
            }

            return $invoice->refresh();
        });
    }
}
