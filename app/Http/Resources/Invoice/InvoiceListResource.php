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
 * @property string|null $employee_name
 * @property string|null $service_name
 * @property string|null $created_at
 * @property int|null $user_id
 */
class InvoiceListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $type = InvoiceTypeEnum::from($this->type);
        $status = InvoiceStatusEnum::from($this->status);

        // Owner-employee controls, mirroring ServiceInvoicePolicy. The list can't
        // know the refund/agent-payment guards, so an edit/delete opens the
        // invoice where the server has the final, guard-aware say.
        $user = $request->user();
        $isOwnerEmployee = $user !== null
            && $type === InvoiceTypeEnum::SERVICE
            && $user->roleName->isEmployee()
            && (int) $this->user_id === $user->id;

        return [
            'id' => (int) $this->id,
            'type' => $type->value,
            'typeLabel' => $type->label(),
            'serviceNames' => $this->service_name !== null && $this->service_name !== ''
                ? array_values(array_filter(array_map('trim', explode(',', $this->service_name))))
                : [],
            'invoiceNumber' => $this->invoice_number,
            'totalAmount' => (float) $this->total_amount,
            'status' => $status->value,
            'statusLabel' => $status->label(),
            'customerName' => $this->customer_name,
            'employeeName' => $this->employee_name,
            'createdAt' => $this->created_at,
            'canEdit' => $isOwnerEmployee && $status === InvoiceStatusEnum::DUE,
            'canDelete' => $isOwnerEmployee && $status !== InvoiceStatusEnum::CANCELLED,
        ];
    }
}
