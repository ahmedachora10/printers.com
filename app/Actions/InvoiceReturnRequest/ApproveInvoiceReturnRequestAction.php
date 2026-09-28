<?php

namespace App\Actions\InvoiceReturnRequest;

use App\Actions\Refund\CreateRefundAction;
use App\Actions\ServiceInvoice\ReturnServiceInvoiceAction;
use App\Enums\InvoiceTypeEnum;
use App\Enums\ReturnRequestStatusEnum;
use App\Models\InvoiceReturnRequest;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * تاسك 135 — الاعتماد والتنفيذ نقرةٌ واحدة: المعتمد يختار طريقة الردّ فيجري
 * الاسترجاع القائم نفسه (الحالة returned، المرتجع بطريقته، عكس العمولة غير
 * المدفوعة والولاء والخامات) ويُربط الطلب بمرتجعه.
 */
class ApproveInvoiceReturnRequestAction
{
    public function __construct(
        private readonly ReturnServiceInvoiceAction $returnInvoice,
        private readonly CreateRefundAction $createRefund,
    ) {}

    /**
     * $amount أقلّ مما حُصِّل = مرتجع جزئي بمسار مرتجع مدير الفرع نفسه: تبقى
     * الفاتورة قائمة وتُعكس العمولة والولاء نسبياً ولا تعود الخامات. الفارغ أو
     * كامل المحصَّل = الاسترجاع الكامل.
     */
    public function handle(InvoiceReturnRequest $request, User $actor, ?int $paymentMethodId, ?float $amount = null): InvoiceReturnRequest
    {
        return DB::transaction(function () use ($request, $actor, $paymentMethodId, $amount) {
            $request = $request->lockPending();

            $invoice = $request->invoice;
            $refunds = Refund::query()
                ->where('invoice_type', $invoice->getMorphClass())
                ->where('invoice_id', $invoice->id);
            $lastRefundId = (int) (clone $refunds)->max('id');

            if ($amount !== null && round($amount, 2) < $this->returnInvoice->refundableCollected($invoice)) {
                $this->createRefund->handle([
                    'source_type' => InvoiceTypeEnum::SERVICE->value,
                    'invoice_id' => $invoice->id,
                    'amount' => $amount,
                    'reason' => $request->reason ?: "استرجاع جزئي للفاتورة {$invoice->invoice_number}",
                    'payment_method_id' => $paymentMethodId,
                ], $actor);
            } else {
                $this->returnInvoice->handle($invoice, $actor, $request->reason, $paymentMethodId);
            }

            // المرتجع الذي كتبه الاسترجاع للتو — null إن لم يبقَ ما يُردّ.
            $refundId = $refunds->where('id', '>', $lastRefundId)->value('id');

            $request->update([
                'status' => ReturnRequestStatusEnum::COMPLETED,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'refund_id' => $refundId,
            ]);

            return $request;
        });
    }
}
