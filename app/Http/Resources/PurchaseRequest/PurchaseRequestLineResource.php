<?php

namespace App\Http\Resources\PurchaseRequest;

use App\Models\PurchaseRequestLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseRequestLine
 *
 * تاسك 89: السطر يحمل واقعتين — ما طلبه الموظف وما اعتمده المسؤول. المفاتيح
 * القديمة (itemName/qty/estimatedUnitCost) تبقى **الفعلية** كما كانت تُقرأ قبل
 * الإصلاح، ويُضاف إليها الطرفان صريحَين لتعرضهما الشاشة متجاورين.
 */
class PurchaseRequestLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $approvedName = $this->whenLoaded('approvedProduct', fn () => $this->approvedProduct?->name);

        return [
            'id' => $this->id,
            'productId' => $this->effectiveProductId(),
            // الفعلي: القرار إن وُجد، وإلا الطلب.
            'itemName' => $this->approved_product_id !== null && is_string($approvedName)
                ? $approvedName
                : $this->item_name,
            'sku' => $this->whenLoaded('product', fn () => $this->product?->sku),
            'qty' => $this->effectiveQty(),
            'isSqm' => $this->effectiveIsSqm(),
            'estimatedUnitCost' => $this->effectiveUnitCost(),
            'estimatedSubtotal' => $this->effectiveQty() * ($this->effectiveUnitCost() ?? 0),
            'notes' => $this->notes,

            // ── ما طلبه الموظف، كما كتبه ──────────────────────────
            'requestedItemName' => $this->item_name,
            'requestedQty' => (float) $this->qty,
            'requestedIsSqm' => (bool) $this->is_sqm,
            'requestedUnitCost' => $this->estimated_unit_cost !== null ? (float) $this->estimated_unit_cost : null,
            'requestedSku' => $this->whenLoaded('product', fn () => $this->product?->sku),

            // ── ما اعتمده المسؤول ─────────────────────────────────
            'approvedProductId' => $this->approved_product_id,
            'approvedProductName' => $approvedName,
            'approvedSku' => $this->whenLoaded('approvedProduct', fn () => $this->approvedProduct?->sku),
            'approvedQty' => $this->approved_qty !== null ? (float) $this->approved_qty : null,
            'approvedIsSqm' => $this->approved_is_sqm !== null ? (bool) $this->approved_is_sqm : null,
            'approvedUnitCost' => $this->approved_unit_cost !== null ? (float) $this->approved_unit_cost : null,
            'wasSettled' => $this->wasSettled(),
        ];
    }
}
