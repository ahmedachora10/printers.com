<?php

namespace App\Http\Resources\Invoice;

use App\Models\ProductInvoiceLine;
use App\Models\ServiceInvoiceLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Normalizes ProductInvoiceLine and ServiceInvoiceLine into one shape.
 * Fields that exist on only one of the two line types are declared
 * explicitly as nullable since the mixin union cannot resolve them.
 *
 * @mixin ProductInvoiceLine|ServiceInvoiceLine
 *
 * @property string|null $service_name
 * @property string|null $sku
 * @property string|null $commission_amount
 */
class InvoiceLineResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isService = $this->resource instanceof ServiceInvoiceLine;

        return [
            'name' => $this->product_name ?? $this->service_name,
            // Free-text detail; service lines only — product lines have no field.
            'notes' => $isService ? $this->resource->notes : null,
            'sku' => $this->sku,
            'qty' => $this->qty,
            'unitPrice' => (float) $this->unit_price,
            'widthCm' => $isService && $this->resource->width_cm !== null
                ? (float) $this->resource->width_cm
                : null,
            'heightCm' => $isService && $this->resource->height_cm !== null
                ? (float) $this->resource->height_cm
                : null,
            'discountPct' => (float) $this->discount_pct,
            'subtotal' => (float) $this->subtotal,
            'commissionAmount' => $this->commission_amount !== null
                ? (float) $this->commission_amount
                : null,
            'lineAgentName' => $isService ? $this->resource->lineAgent?->name : null,
            'lineAgentCommissionAmount' => $isService && $this->resource->agent_id !== null
                ? (float) $this->resource->agent_commission_amount
                : null,
        ];
    }
}
