<?php

namespace App\Http\Resources\PurchaseRequest;

use App\Models\PurchaseRequestLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseRequestLine
 */
class PurchaseRequestLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'itemName' => $this->item_name,
            'sku' => $this->whenLoaded('product', fn () => $this->product?->sku),
            'qty' => (float) $this->qty,
            'isSqm' => (bool) $this->is_sqm,
            'estimatedUnitCost' => $this->estimated_unit_cost !== null ? (float) $this->estimated_unit_cost : null,
            'estimatedSubtotal' => (float) ($this->qty * (float) ($this->estimated_unit_cost ?? 0)),
            'notes' => $this->notes,
        ];
    }
}
