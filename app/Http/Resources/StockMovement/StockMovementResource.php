<?php

namespace App\Http\Resources\StockMovement;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'productName' => $this->product?->name,
            'sku' => $this->product?->sku,
            'type' => $this->type->value,
            'typeLabel' => $this->type->label(),
            'qty' => $this->qty,
            'unitCost' => $this->unit_cost !== null ? (float) $this->unit_cost : null,
            'notes' => $this->notes,
            'createdByName' => $this->creator?->name,
            'createdAt' => $this->created_at->format('d/m/Y H:i'),
        ];
    }
}
