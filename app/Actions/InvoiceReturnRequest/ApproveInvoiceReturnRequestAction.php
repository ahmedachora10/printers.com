<?php

namespace App\Actions\InvoiceReturnRequest;

use App\Actions\ServiceInvoice\ReturnServiceInvoiceAction;
use App\Enums\ReturnRequestStatusEnum;
use App\Models\InvoiceReturnRequest;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تاسك 135 — الاعتماد والتنفيذ نقرةٌ واحدة: المعتمد يختار طريقة الردّ فيجري
 * الاسترجاع القائم نفسه (الحالة returned، المرتجع بطريقته، عكس العمولة غير
 * المدفوعة والولاء والخامات) ويُربط الطلب بمرتجعه.
 */
class ApproveInvoiceReturnRequestAction
{
    public function __construct(private readonly ReturnServiceInvoiceAction $returnInvoice) {}

    public function handle(InvoiceReturnRequest $request, User $actor, ?int $paymentMethodId): InvoiceReturnRequest
    {
        return DB::transaction(function () use ($request, $actor, $paymentMethodId) {
            $request = InvoiceReturnRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->status !== ReturnRequestStatusEnum::PENDING) {
                throw ValidationException::withMessages(['status' => 'تم البتّ في هذا الطلب بالفعل.']);
            }

            $invoice = $request->invoice;
            $refunds = Refund::query()
                ->where('invoice_type', $invoice->getMorphClass())
                ->where('invoice_id', $invoice->id);
            $lastRefundId = (int) (clone $refunds)->max('id');

            $this->returnInvoice->handle($invoice, $actor, $request->reason, $paymentMethodId);

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
