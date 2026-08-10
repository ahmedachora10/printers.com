<?php

namespace App\Http\Resources\Invoice;

use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use App\Models\InvoicePayment;
use App\Models\ProductInvoice;
use App\Models\Refund;
use App\Models\ServiceInvoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full invoice detail for the unified viewer (M13). Works for both
 * ProductInvoice and ServiceInvoice.
 *
 * @mixin ProductInvoice|ServiceInvoice
 */
class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $type = $this->resource instanceof ServiceInvoice
            ? InvoiceTypeEnum::SERVICE
            : InvoiceTypeEnum::PRODUCT;

        $total = (float) $this->total_amount;
        $refundedTotal = $this->relationLoaded('refunds')
            ? round((float) $this->refunds->sum('amount'), 2)
            : 0.0;
        $refundableRemaining = round($total - $refundedTotal, 2);

        $user = $request->user();
        $canRefund = $user !== null
            && $user->can('create', Refund::class)
            && ($user->roleName->isSuperAdmin() || $user->branchId === $this->branch_id)
            && $this->status !== InvoiceStatusEnum::CANCELLED
            && $refundableRemaining > 0;

        // Settling a due service invoice straight from the viewer, mirroring the
        // review queue. Product invoices have no equivalent payment approval.
        $canApprovePayment = $user !== null
            && $this->resource instanceof ServiceInvoice
            && $this->status === InvoiceStatusEnum::DUE
            && $user->can('updateStatus', $this->resource);

        // The owning employee may re-edit their invoice while it is still DUE, and
        // return it (before or after approval) — but a return is blocked once the
        // invoice has been rolled into an agent payment.
        $isServiceInvoice = $this->resource instanceof ServiceInvoice;

        // The agents on the invoice: several via the pivot for a service invoice,
        // or the single row-level agent for a product invoice.
        $agents = $isServiceInvoice
            ? $this->invoiceAgents->map(fn ($a) => [
                'name' => $a->agent?->name,
                'mode' => $a->discount_mode->value,
                'rebate' => (float) $a->rebate_amount,
                'lineCommission' => (float) $a->line_commission_amount,
                'discount' => (float) $a->discount_amount,
                'isRebatePaid' => $a->agent_payment_id !== null,
            ])->values()->all()
            : ($this->agent ? [[
                'name' => $this->agent->name,
                'mode' => (float) $this->agent_rebate > 0 ? 'rebate' : 'discount',
                'rebate' => (float) $this->agent_rebate,
                'lineCommission' => 0.0,
                'discount' => (float) $this->agent_discount,
                'isRebatePaid' => $this->agent_payment_id !== null,
            ]] : []);

        $hasSettledAgent = $isServiceInvoice
            ? $this->invoiceAgents->whereNotNull('agent_payment_id')->isNotEmpty()
            : $this->agent_payment_id !== null;

        // الدفعات (عربون + دفعات لاحقة): المحصَّل والمتبقي مصدرهما صفوف الدفعات،
        // بينما total_amount يبقى السعر المتفق عليه.
        $paidAmount = $this->resource->paidAmount();
        $paymentRemaining = $this->resource->remainingAmount();
        $canRecordPayment = $user !== null && $user->can('recordPayment', $this->resource);

        $canEdit = $user !== null && $isServiceInvoice && $user->can('update', $this->resource);
        $canReturn = $user !== null
            && $isServiceInvoice
            && $user->can('returnInvoice', $this->resource)
            && ! $hasSettledAgent;

        return [
            'id' => $this->id,
            'type' => $type->value,
            'typeLabel' => $type->label(),
            'invoiceNumber' => $this->invoice_number,
            'createdAt' => $this->created_at?->toIso8601String(),
            'paidAt' => $this->paid_at?->toIso8601String(),
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            // Why the invoice was rejected, for everyone who may view it — the
            // employee who raised it above all (تاسك 18). Product invoices have
            // no cancellation flow, hence no reason to carry.
            'cancellationReason' => $isServiceInvoice ? $this->resource->cancellation_reason : null,
            'cancelledByName' => $isServiceInvoice ? $this->resource->cancelledBy?->name : null,
            'cancelledAt' => $isServiceInvoice ? $this->resource->cancelled_at?->toIso8601String() : null,
            // موعد تسليم العمل للعميل — لفواتير الخدمات وحدها؛ تسليم المنتجات فوري.
            'deliveryAt' => $isServiceInvoice ? $this->resource->delivery_at?->toIso8601String() : null,
            'deliveryStatus' => $isServiceInvoice ? $this->resource->deliveryStatus()?->value : null,
            'subtotal' => (float) $this->subtotal,
            'tierDiscountPct' => (float) $this->tier_discount_pct,
            'tierDiscountAmount' => (float) $this->tier_discount_amount,
            'couponDiscount' => (float) $this->coupon_discount,
            'agentDiscount' => (float) $this->agent_discount,
            'agents' => $agents,
            'pointsRedeemed' => (int) $this->points_redeemed,
            'pointsDiscount' => (float) $this->points_discount,
            'vatPct' => (float) $this->vat_pct,
            'vatAmount' => (float) $this->vat_amount,
            'totalAmount' => (float) $this->total_amount,
            'employeeCommission' => $this->resource instanceof ServiceInvoice
                ? (float) $this->resource->employee_commission
                : null,
            'customerName' => $this->customer?->full_name,
            'customerPhone' => $this->customer?->phone,
            'customerTaxNumber' => $this->customer?->tax_number ?? null,
            'paymentMethod' => $this->paymentMethod?->name,
            // Invoice-level remark for the customer — distinct from the
            // per-line detail carried on InvoiceLineResource::notes.
            'notes' => $this->notes,
            'receiptUrl' => $this->receiptUrl(),
            'lines' => InvoiceLineResource::collection($this->whenLoaded('lines')),
            'refundedTotal' => $refundedTotal,
            'refundableRemaining' => $refundableRemaining,
            'isFullyRefunded' => $refundedTotal > 0 && $refundableRemaining <= 0,
            'paidAmount' => $paidAmount,
            'paymentRemaining' => $paymentRemaining,
            'canRecordPayment' => $canRecordPayment,
            'payments' => $this->whenLoaded('payments', fn () => $this->payments
                ->sortBy('paid_at')
                ->map(fn (InvoicePayment $payment) => [
                    'id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'paidAt' => $payment->paid_at?->toIso8601String(),
                    'paymentMethod' => $payment->paymentMethod?->name,
                    'recordedByName' => $payment->recordedBy?->name,
                    'notes' => $payment->notes,
                    'receiptUrl' => $payment->receiptUrl(),
                ])->values()->all()),
            'canRefund' => $canRefund,
            'canApprovePayment' => $canApprovePayment,
            'canEdit' => $canEdit,
            'canReturn' => $canReturn,
            'refunds' => $this->whenLoaded('refunds', fn () => $this->refunds
                ->map(fn (Refund $refund) => [
                    'id' => $refund->id,
                    'amount' => (float) $refund->amount,
                    'reason' => $refund->reason,
                    'stockReversed' => (bool) $refund->stock_reversed,
                    'userName' => $refund->user?->name,
                    'createdAt' => $refund->created_at?->toIso8601String(),
                ])->values()->all()),
            'branch' => [
                'name' => $this->branch?->name,
                'phone' => $this->branch?->phone,
                'address' => $this->branch?->address,
                'taxNumber' => $this->branch?->tax_number,
                'logoUrl' => $this->branch?->getFirstMediaUrl('logo') ?: null,
            ],
        ];
    }
}
