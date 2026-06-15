<?php

namespace App\Http\Resources\PurchaseOrder;

use App\Models\PurchaseOrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseOrderLine
 */
class PurchaseOrderLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'productName' => $this->product?->name,
            'sku' => $this->product?->sku,
            'orderedQty' => $this->ordered_qty,
            'receivedQty' => $this->received_qty,
            'remainingQty' => $this->remainingQty(),
            'unitCost' => (float) $this->unit_cost,
            'subtotal' => (float) $this->subtotal,
        ];
    }
}
