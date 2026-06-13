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
        return [
            'name' => $this->product_name ?? $this->service_name,
            'sku' => $this->sku,
            'qty' => $this->qty,
            'unitPrice' => (float) $this->unit_price,
            'discountPct' => (float) $this->discount_pct,
            'subtotal' => (float) $this->subtotal,
            'commissionAmount' => $this->commission_amount !== null
                ? (float) $this->commission_amount
                : null,
        ];
    }
}
