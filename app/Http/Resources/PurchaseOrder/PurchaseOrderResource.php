<?php

namespace App\Http\Resources\PurchaseOrder;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'poNumber' => $this->po_number,
            'supplierId' => $this->supplier_id,
            'supplierName' => $this->supplier?->name,
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'canReceive' => $this->status->canReceive(),
            'canCancel' => $this->status->canCancel(),
            'canEdit' => $this->status->canEdit(),
            'orderDate' => $this->order_date?->format('d/m/Y'),
            'expectedDelivery' => $this->expected_delivery?->format('d/m/Y'),
            'orderedByName' => $this->orderedBy?->name,
            'total' => $this->whenLoaded('lines', fn () => $this->total()),
            'linesCount' => $this->whenCounted('lines'),
            'notes' => $this->notes,
            'lines' => PurchaseOrderLineResource::collection($this->whenLoaded('lines')),
        ];
    }
}
