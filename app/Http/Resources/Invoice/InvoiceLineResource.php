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
 * @property string|null $width_cm
 * @property string|null $height_cm
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
            'qty' => (float) $this->qty,
            'unitPrice' => (float) $this->unit_price,
            // ما يقيسه السعر أعلاه: 'sqm' سعر متر مربع و'linear' سعر متر طولي
            // (تاسك 80)، و null سعر وحدة/قطعة — وهو حال كل سطر منتج وكل سطر
            // خدمة قديم لا عمود يميّزه.
            'unitPriceBasis' => $isService && $this->resource->isPricedPerSqm()
                ? $this->resource->unit_price_basis?->value
                : null,
            // كلا نوعَي السطر يحملان مقاساً الآن: الخدمة المسعّرة بالمتر (تاسك 44)
            // والمنتج المسعّر بالمتر (تاسك 51).
            'widthCm' => $this->width_cm !== null ? (float) $this->width_cm : null,
            'heightCm' => $this->height_cm !== null ? (float) $this->height_cm : null,
            // عدد القطع لسطر المنتج بالمتر — الكمية أعلاه مساحتها الإجمالية.
            'pieces' => ! $isService && $this->resource->pieces !== null
                ? (int) $this->resource->pieces
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
