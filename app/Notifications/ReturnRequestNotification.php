<?php

namespace App\Notifications;

use App\Enums\ReturnRequestStatusEnum;
use App\Models\InvoiceReturnRequest;
use Illuminate\Notifications\Notification;

/**
 * تاسك 135 — طلب استرجاع: عند رفعه للمعتمدين (pending)، وعند البتّ فيه
 * للموظف صاحبه (completed / rejected بسببه).
 */
class ReturnRequestNotification extends Notification
{
    public function __construct(
        private readonly InvoiceReturnRequest $request,
        private readonly string $invoiceNumber,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $invoiceUrl = route('invoices.show', ['type' => 'service', 'id' => $this->request->service_invoice_id]);

        return match ($this->request->status) {
            ReturnRequestStatusEnum::PENDING => [
                'type' => 'return_request_submitted',
                'title' => 'طلب استرجاع جديد',
                'body' => "طلب {$this->request->requester?->name} استرجاع الفاتورة {$this->invoiceNumber}.",
                'url' => route('refunds.requests.index'),
                'icon' => 'Undo2',
            ],
            ReturnRequestStatusEnum::COMPLETED => [
                'type' => 'return_request_completed',
                'title' => 'تم استرجاع الفاتورة',
                'body' => "تم اعتماد طلب استرجاع الفاتورة {$this->invoiceNumber} وتنفيذه.",
                'url' => $invoiceUrl,
                'icon' => 'ClipboardCheck',
            ],
            ReturnRequestStatusEnum::REJECTED => [
                'type' => 'return_request_rejected',
                'title' => 'تم رفض طلب الاسترجاع',
                'body' => "تم رفض طلب استرجاع الفاتورة {$this->invoiceNumber} — السبب: {$this->request->rejection_reason}",
                'url' => $invoiceUrl,
                'icon' => 'Undo2',
            ],
        };
    }
}
