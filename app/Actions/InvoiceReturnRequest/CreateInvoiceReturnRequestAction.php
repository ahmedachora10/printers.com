<?php

namespace App\Actions\InvoiceReturnRequest;

use App\Actions\ServiceInvoice\ReturnServiceInvoiceAction;
use App\Enums\ReturnRequestStatusEnum;
use App\Models\InvoiceReturnRequest;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تاسك 135 — الموظف لا يُرجع فاتورةً حُصِّل منها مبلغ بنفسه: يرفع طلباً، ولا
 * تتغيّر الفاتورة حتى يعتمده المحاسب أو الإدارة.
 */
class CreateInvoiceReturnRequestAction
{
    public function __construct(private readonly ReturnServiceInvoiceAction $returnInvoice) {}

    public function handle(ServiceInvoice $invoice, User $requester, ?string $reason): InvoiceReturnRequest
    {
        $this->returnInvoice->assertReturnable($invoice);

        return DB::transaction(function () use ($invoice, $requester, $reason) {
            $hasOpen = $invoice->returnRequests()
                ->where('status', ReturnRequestStatusEnum::PENDING)
                ->lockForUpdate()
                ->exists();

            if ($hasOpen) {
                throw ValidationException::withMessages([
                    'invoice' => 'يوجد طلب استرجاع لهذه الفاتورة تحت المراجعة.',
                ]);
            }

            return InvoiceReturnRequest::create([
                'service_invoice_id' => $invoice->id,
                'branch_id' => $invoice->branch_id,
                'requested_by' => $requester->id,
                'reason' => $reason ?: null,
                'status' => ReturnRequestStatusEnum::PENDING,
            ]);
        });
    }
}
