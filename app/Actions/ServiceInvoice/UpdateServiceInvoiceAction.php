<?php

namespace App\Actions\ServiceInvoice;

use App\Actions\Incentive\RecalculateIncentivePlanAction;
use App\Actions\InvoiceMessage\PostInvoiceMessageAction;
use App\Actions\ServiceInvoice\Concerns\LogsAuthoredMaterialsCost;
use App\Actions\ServiceInvoice\Concerns\ReversesServiceInvoiceAccruals;
use App\Actions\ServiceInvoice\Concerns\SyncsServiceInvoiceAgents;
use App\Actions\ServiceInvoice\Concerns\SyncsServiceInvoiceShipments;
use App\Actions\ServiceInvoice\Concerns\WritesServiceInvoiceLines;
use App\Enums\InvoiceStatusEnum;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Re-edits a service invoice in place, keeping the same invoice number and id.
 * The invoice's existing accruals are unwound first (unpaid commission
 * reversed, redeemed points restored, earned points clawed back, coupon
 * released, materials put back), then the whole invoice is recomputed from the
 * submitted data and re-persisted — and, for an approved invoice, approval's
 * effects are written again (MarkServiceInvoicePaidAction::realise). The
 * status never moves, except a partially paid invoice edited down to what was
 * collected, which completes. Who may edit which status: ServiceInvoicePolicy::update.
 */
class UpdateServiceInvoiceAction
{
    use LogsAuthoredMaterialsCost, ReversesServiceInvoiceAccruals, SyncsServiceInvoiceAgents, SyncsServiceInvoiceShipments, WritesServiceInvoiceLines;

    public function __construct(
        private readonly CalculateServiceInvoiceAction $calculator,
        private readonly PostInvoiceMessageAction $postMessage,
        private readonly MarkServiceInvoicePaidAction $markPaid,
        private readonly ConsumeServiceMaterialsAction $consumeMaterials,
        private readonly RecalculateIncentivePlanAction $recalculateIncentive,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(ServiceInvoice $invoice, array $data, ?UploadedFile $receipt = null): ServiceInvoice
    {
        // An agent rebate already rolled into a payment would be left dangling if
        // the invoice were recomputed underneath it.
        if ($invoice->invoiceAgents()->whereNotNull('agent_payment_id')->exists()) {
            throw ValidationException::withMessages([
                'invoice' => 'لا يمكن تعديل فاتورة مُدرجة ضمن دفعة مندوب.',
            ]);
        }

        // عمولةٌ صُرفت للموظف لا يُعكس صفّها (reverseUnpaidCommission يتخطّاه)،
        // فإعادة كتابة العمولة فوقه تدفعها له مرتين.
        if (CommissionLedger::query()
            ->where('invoice_line_type', ServiceInvoiceLine::class)
            ->whereIn('invoice_line_id', $invoice->lines()->select('id'))
            ->whereNotNull('paid_at')
            ->exists()) {
            throw ValidationException::withMessages([
                'invoice' => 'لا يمكن تعديل فاتورة صُرفت عمولتها للموظف.',
            ]);
        }

        $branchId = (int) $invoice->branch_id;
        // المعتمَدة طُبعت ضريبيةً بنسبتها، فتبقى عليها وإن تغيّرت نسبة الفرع.
        $vatPct = $invoice->status === InvoiceStatusEnum::DUE
            ? (float) Branch::findOrFail($branchId)->vat_rate_override
            : (float) $invoice->vat_pct;

        return DB::transaction(function () use ($invoice, $data, $receipt, $branchId, $vatPct) {
            // Unwind the current invoice before recomputing. Restoring the
            // redeemed points first means the recomputation sees the customer's
            // real balance, so an unchanged redemption nets to zero. The
            // commission reversal keeps each row's period: the rewritten rows
            // land on the invoice's paid_at, so the month nets to the new figure.
            $this->reverseUnpaidCommission($invoice, keepEarnedAt: true);
            $this->restoreRedeemedPoints($invoice);
            $this->clawBackEarnedPoints($invoice);
            $this->releaseCoupon($invoice);
            // قبل حذف الأسطر: حركات الإرجاع تحمل رقم السطر الذي صُرفت له.
            $this->consumeMaterials->restore($invoice, (int) auth()->id());
            $invoice->lines()->delete();

            // الفاتورة نفسها تُستثنى من حساب النقاط المحجوزة، فإعادة إرسال العدد
            // نفسه لا تصطدم بحجزها هي.
            $calc = $this->calculator->handle($data, $invoice->user, $branchId, $vatPct, $invoice);

            $invoice->update($calc['attributes']);
            $invoice->settleAfterEdit((int) auth()->id());

            // تاسك 100: ما يُكتب في الخانة عند التعديل رسالةٌ جديدة ممّن يعدّل —
            // لا استبدالٌ لما قيل قبلها.
            if (filled($data['internal_notes'] ?? null)) {
                $this->postMessage->handle($invoice, auth()->user(), $data['internal_notes']);
            }

            if ($receipt !== null) {
                $invoice->clearMediaCollection(ServiceInvoice::RECEIPT_COLLECTION);
                $invoice->addMedia($receipt)->toMediaCollection(ServiceInvoice::RECEIPT_COLLECTION);
            }

            $this->writeLines($invoice, $calc['lines']);

            $this->syncShipments($invoice, $calc['shipments']);
            $this->logAuthoredMaterialsCost($invoice, $calc['lines']);
            $this->syncInvoiceAgents($invoice, $calc['agents']);

            if ($calc['coupon']) {
                $calc['coupon']->increment('used_count');
            }

            // الآجلة والمدفوعة جزئياً: لا عمولة ولا نقاط مخصومة ولا خامات بعد —
            // تنتظر الاعتماد. المدفوعة يُعاد عليها ما كتبه اعتمادها.
            if ($invoice->status === InvoiceStatusEnum::PAID) {
                $this->markPaid->realise($invoice);
            } elseif ($invoice->status === InvoiceStatusEnum::PARTIALLY_PAID) {
                $this->recalculateIncentive->refreshForInvoice($invoice);
            }

            return $invoice->refresh();
        });
    }
}
