<?php

namespace App\Http\Resources\Invoice;

use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single row of the unified invoices list (M13). Wraps a stdClass row
 * produced by the UNION query in InvoiceController::index.
 *
 * @property int|string $id
 * @property string $type
 * @property string $status
 * @property string $invoice_number
 * @property string $total_amount
 * @property string|null $customer_name
 * @property string|null $created_at
 */
class InvoiceListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $type = InvoiceTypeEnum::from($this->type);
        $status = InvoiceStatusEnum::from($this->status);

        return [
            'id' => (int) $this->id,
            'type' => $type->value,
            'typeLabel' => $type->label(),
            'invoiceNumber' => $this->invoice_number,
            'totalAmount' => (float) $this->total_amount,
            'status' => $status->value,
            'statusLabel' => $status->label(),
            'customerName' => $this->customer_name,
            'createdAt' => $this->created_at,
        ];
    }
}
