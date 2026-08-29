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
 * @property string|null $paid_amount
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
 * @property string|null $delivered_at
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

        // A reviewer of service invoices — branch admin, accountant or super admin.
        // They edit the customer inline (invoices.service.update-customer) and,
        // since تاسك 70, the due invoice itself. Never employees. The server keeps
        // the final, policy-aware say on either update.
        $isReviewer = $user !== null
            && $type === InvoiceTypeEnum::SERVICE
            && ($user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin() || $user->roleName->isAccountant());

        $canEditCustomer = $isReviewer;

        // Who may stamp "تم تسليم العمل" from the list, mirroring
        // ServiceInvoicePolicy::deliver — the owning employee or a reviewer, on a
        // service invoice that is still live and not delivered yet. The list row
        // is a raw union record, not a model, hence the repetition.
        $canDeliver = $type === InvoiceTypeEnum::SERVICE
            && ($isOwnerEmployee || $isReviewer)
            && $this->delivered_at === null
            && $status !== InvoiceStatusEnum::CANCELLED
            && $status !== InvoiceStatusEnum::RETURNED;

        return [
            'id' => (int) $this->id,
            'type' => $type->value,
            'typeLabel' => $type->label(),
            'serviceNames' => $this->service_name !== null && $this->service_name !== ''
                ? array_values(array_filter(array_map('trim', explode(',', $this->service_name))))
                : [],
            'invoiceNumber' => $this->invoice_number,
            'totalAmount' => (float) $this->total_amount,
            // ما حُصِّل وما بقي على العميل. الفاتورة التي سُدِّدت عند البيع لا تحمل
            // صفوف دفعات، فالمحصَّل منها إجمالُها؛ والملغاة/المرتجعة لا مطالبة عليها.
            'paidAmount' => $this->collectedAmount($status),
            'remainingAmount' => $this->remainingAmount($status),
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
            // موعد تسليم العمل وحالته (تم التسليم / متأخر / اليوم / قادم) — فواتير
            // الخدمات فقط. الصف يأتي من استعلام اتحاد خام، فالتاريخ نص يُحوّل هنا.
            'deliveryAt' => $this->delivery_at,
            'deliveredAt' => $this->delivered_at,
            'deliveryStatus' => DeliveryStatusEnum::forInvoice(
                $this->delivered_at !== null ? CarbonImmutable::parse($this->delivered_at) : null,
                $this->delivery_at !== null ? CarbonImmutable::parse($this->delivery_at) : null,
                $status,
            )?->value,
            // زر «تم تسليم العمل» السريع في صف القائمة. الصف خام لا موديل، فتُكرَّر
            // شروط ServiceInvoicePolicy::deliver هنا؛ الخادم يبقى صاحب القرار
            // النهائي عند الضغط.
            'canDeliver' => $canDeliver,
            // تاسك 70: يعدّل الفاتورة المعلّقة صاحبُها الموظف أو مراجعٌ في فرعها —
            // وهم أنفسهم من يعدّلون بيانات العميل هنا — مرآةً لـServiceInvoicePolicy::update.
            'canEdit' => ($isOwnerEmployee || $isReviewer) && $status === InvoiceStatusEnum::DUE,
            'canReturn' => $isOwnerEmployee
                && $status !== InvoiceStatusEnum::CANCELLED
                && $status !== InvoiceStatusEnum::RETURNED,
            // The owner of an already-returned invoice still sees the control,
            // disabled — so the row reads as "returned", not as "not yours".
            'returnLocked' => $isOwnerEmployee && $status === InvoiceStatusEnum::RETURNED,
            'canEditCustomer' => $canEditCustomer,
        ];
    }

    /**
     * ما حُصِّل من الفاتورة: مجموع دفعاتها إن وُجدت، وإلا إجمالُها إن كانت مسدَّدة.
     * `paid_amount` يأتي من استعلام الاتحاد كمجموع صفوف invoice_payments.
     */
    private function collectedAmount(InvoiceStatusEnum $status): float
    {
        $collected = round((float) $this->paid_amount, 2);

        if ($collected > 0.0) {
            return $collected;
        }

        return $status === InvoiceStatusEnum::PAID ? round((float) $this->total_amount, 2) : 0.0;
    }

    /** المتبقي على العميل — صفر للفاتورة الملغاة أو المرتجعة، فلا مطالبة عليها. */
    private function remainingAmount(InvoiceStatusEnum $status): float
    {
        if ($status === InvoiceStatusEnum::CANCELLED || $status === InvoiceStatusEnum::RETURNED) {
            return 0.0;
        }

        return round(max((float) $this->total_amount - $this->collectedAmount($status), 0), 2);
    }
}
