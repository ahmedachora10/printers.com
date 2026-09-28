<?php

namespace App\Http\Resources\InvoiceReturnRequest;

use App\Actions\ServiceInvoice\ReturnServiceInvoiceAction;
use App\Enums\ReturnRequestStatusEnum;
use App\Models\InvoiceReturnRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InvoiceReturnRequest
 */
class InvoiceReturnRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isPending = $this->status === ReturnRequestStatusEnum::PENDING;

        return [
            'id' => $this->id,
            'invoiceId' => $this->service_invoice_id,
            'invoiceNumber' => $this->invoice?->invoice_number,
            'branchId' => $this->branch_id,
            // ما سيُردّ للعميل لو اعتُمد الآن؛ وبعد الإتمام مبلغ مرتجعه.
            'amount' => $isPending
                ? app(ReturnServiceInvoiceAction::class)->refundableCollected($this->invoice)
                : (float) ($this->refund?->amount ?? 0),
            'paymentMethodName' => $this->refund?->paymentMethod?->name,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'requesterName' => $this->requester?->name,
            'deciderName' => $this->decider?->name,
            'rejectionReason' => $this->rejection_reason,
            'createdAt' => $this->created_at?->toIso8601String(),
            'decidedAt' => $this->decided_at?->toIso8601String(),
            'canDecide' => $isPending && ($request->user()?->can('updateStatus', $this->invoice) ?? false),
        ];
    }
}
