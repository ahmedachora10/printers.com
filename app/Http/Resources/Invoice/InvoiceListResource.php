<?php

namespace App\Http\Resources\Invoice;

use App\Enums\DeliveryStatusEnum;
use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use Carbon\CarbonImmutable;
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
 * @property int|null $customer_id
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property string|null $customer_tax_number
 * @property string|null $employee_name
 * @property string|null $service_name
 * @property string|null $created_at
 * @property int|null $user_id
 * @property string|null $branch_name
 * @property string|null $cancellation_reason
 * @property string|null $delivery_at
 */
class InvoiceListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $type = InvoiceTypeEnum::from($this->type);
        $status = InvoiceStatusEnum::from($this->status);

        // Owner-employee controls, mirroring ServiceInvoicePolicy. The list can't
        // know the agent-payment guard, so an edit/return opens the invoice where
        // the server has the final, guard-aware say.
        $user = $request->user();
        $isOwnerEmployee = $user !== null
            && $type === InvoiceTypeEnum::SERVICE
            && $user->roleName->isEmployee()
            && (int) $this->user_id === $user->id;

        // Inline customer name/phone editing mirrors the review-queue endpoint
        // (invoices.service.update-customer): service invoices only, and only for
        // the roles that guard permits — never employees. The server keeps the
        // final, policy-aware say on the actual update.
        $canEditCustomer = $user !== null
            && $type === InvoiceTypeEnum::SERVICE
            && ($user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin() || $user->roleName->isAccountant());

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
            // Feeds the tooltip on the "ملغاة" badge so the employee sees why
            // their invoice was rejected without opening it (تاسك 18).
            'cancellationReason' => $this->cancellation_reason,
            'customerId' => $this->customer_id !== null ? (int) $this->customer_id : null,
            'customerName' => $this->customer_name,
            'customerPhone' => $this->customer_phone,
            'customerTaxNumber' => $this->customer_tax_number,
            'employeeName' => $this->employee_name,
            // Every other role is already locked to a single branch, so the column
            // would carry no information for them — omit it entirely.
            'branchName' => $this->when(
                $user !== null && $user->roleName->isSuperAdmin(),
                fn () => $this->branch_name,
            ),
            'createdAt' => $this->created_at,
            // موعد تسليم العمل وحالته (متأخر / اليوم / قادم) — فواتير الخدمات فقط.
            // الصف يأتي من استعلام اتحاد خام، فالتاريخ نص يُحوّل هنا.
            'deliveryAt' => $this->delivery_at,
            'deliveryStatus' => DeliveryStatusEnum::forInvoice(
                $this->delivery_at !== null ? CarbonImmutable::parse($this->delivery_at) : null,
                $status,
            )?->value,
            'canEdit' => $isOwnerEmployee && $status === InvoiceStatusEnum::DUE,
            'canReturn' => $isOwnerEmployee
                && $status !== InvoiceStatusEnum::CANCELLED
                && $status !== InvoiceStatusEnum::RETURNED,
            // The owner of an already-returned invoice still sees the control,
            // disabled — so the row reads as "returned", not as "not yours".
            'returnLocked' => $isOwnerEmployee && $status === InvoiceStatusEnum::RETURNED,
            'canEditCustomer' => $canEditCustomer,
        ];
    }
}
