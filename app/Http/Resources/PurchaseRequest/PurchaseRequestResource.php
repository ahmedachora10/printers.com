<?php

namespace App\Http\Resources\PurchaseRequest;

use App\Models\PurchaseRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * @mixin PurchaseRequest
 */
class PurchaseRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branchId' => $this->branch_id,
            'branchName' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'requestedByName' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            'status' => $this->status->value,
            'statusLabel' => $this->status->label(),
            'notes' => $this->notes,
            'decidedByName' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'decidedAt' => $this->decided_at?->format('d/m/Y'),
            'stockFedAt' => $this->stock_fed_at?->format('d/m/Y'),
            'decisionReason' => $this->decision_reason,
            'purchaseOrderId' => $this->purchase_order_id,
            'purchaseOrderNumber' => $this->whenLoaded('purchaseOrder', fn () => $this->purchaseOrder?->po_number),
            'createdAt' => $this->created_at?->format('d/m/Y'),
            'estimatedTotal' => $this->whenLoaded('lines', fn () => $this->estimatedTotal()),
            'linesCount' => $this->whenCounted('lines'),
            'lines' => PurchaseRequestLineResource::collection($this->whenLoaded('lines')),
            // The branch admin only gets the decide/convert buttons on rows
            // they are actually allowed to act on.
            'canDecide' => $this->status->canDecide() && Gate::allows('decide', $this->resource),
            // The model — not the status alone — decides: a request whose
            // approval fed the stock is closed to conversion (تاسك 68).
            'canConvert' => $this->canConvert() && Gate::allows('convert', $this->resource),
        ];
    }
}
