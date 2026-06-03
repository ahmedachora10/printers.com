<?php

namespace App\Http\Resources\Refund;

use App\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Refund
 */
class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sourceType' => $this->source_type->value,
            'sourceTypeLabel' => $this->source_type->label(),
            'invoiceId' => $this->invoice_id,
            'invoiceNumber' => $this->invoice?->invoice_number,
            'amount' => (float) $this->amount,
            'reason' => $this->reason,
            'stockReversed' => (bool) $this->stock_reversed,
            'userName' => $this->user?->name,
            'createdAt' => $this->created_at?->format('d/m/Y H:i'),
        ];
    }
}
