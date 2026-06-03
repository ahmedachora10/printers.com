<?php

namespace App\Http\Resources\Invoice;

use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
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

        return [
            'id' => $this->id,
            'type' => $type->value,
            'typeLabel' => $type->label(),
            'invoiceNumber' => $this->invoice_number,
            'createdAt' => $this->created_at?->toIso8601String(),
            'paidAt' => $this->paid_at?->toIso8601String(),
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'subtotal' => (float) $this->subtotal,
            'tierDiscountPct' => (float) $this->tier_discount_pct,
            'tierDiscountAmount' => (float) $this->tier_discount_amount,
            'couponDiscount' => (float) $this->coupon_discount,
            'pointsRedeemed' => (int) $this->points_redeemed,
            'pointsDiscount' => (float) $this->points_discount,
            'vatPct' => (float) $this->vat_pct,
            'vatAmount' => (float) $this->vat_amount,
            'totalAmount' => (float) $this->total_amount,
            'employeeCommission' => $this->resource instanceof ServiceInvoice
                ? (float) $this->employee_commission
                : null,
            'customerName' => $this->customer?->full_name,
            'customerPhone' => $this->customer?->phone,
            'paymentMethod' => $this->paymentMethod?->name,
            'lines' => InvoiceLineResource::collection($this->whenLoaded('lines')),
            'refundedTotal' => $refundedTotal,
            'refundableRemaining' => $refundableRemaining,
            'isFullyRefunded' => $refundedTotal > 0 && $refundableRemaining <= 0,
            'canRefund' => $canRefund,
            'refunds' => $this->whenLoaded('refunds', fn () => $this->refunds
                ->map(fn (Refund $refund) => [
                    'id' => $refund->id,
                    'amount' => (float) $refund->amount,
                    'reason' => $refund->reason,
                    'stockReversed' => (bool) $refund->stock_reversed,
                    'userName' => $refund->user?->name,
                    'createdAt' => $refund->created_at?->toIso8601String(),
                ])->values()),
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
