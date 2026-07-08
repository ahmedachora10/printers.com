<?php

namespace App\Http\Resources\StockReconciliation;

use App\Models\StockReconciliationLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StockReconciliationLine
 */
class StockReconciliationLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->product_id,
            'productName' => $this->product?->name,
            'sku' => $this->product?->sku,
            'systemQty' => $this->system_qty,
            'physicalQty' => $this->physical_qty,
            'variance' => $this->variance,
            'movementId' => $this->movement_id,
        ];
    }
}
