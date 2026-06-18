<?php

namespace App\Notifications;

use App\Enums\InvoiceStatusEnum;
use App\Enums\InvoiceTypeEnum;
use Illuminate\Notifications\Notification;

/**
 * Tells the employee who raised a due invoice (and the branch admin / super
 * admins) the outcome of the accountant's review: accepted (paid) or
 * rejected (cancelled, with reason).
 */
class ServiceInvoiceReviewedNotification extends Notification
{
    public function __construct(
        private readonly string $invoiceNumber,
        private readonly int $invoiceId,
        private readonly float $total,
        private readonly InvoiceStatusEnum $outcome,
        private readonly ?string $reason = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $total = number_format($this->total, 2);
        $isPaid = $this->outcome === InvoiceStatusEnum::PAID;

        $body = $isPaid
            ? "تم اعتماد دفع الفاتورة {$this->invoiceNumber} بمبلغ {$total} ر.س."
            : "تم رفض الفاتورة {$this->invoiceNumber}".($this->reason ? " — السبب: {$this->reason}" : '.');

        return [
            'type' => $isPaid ? 'invoice_accepted' : 'invoice_rejected',
            'title' => $isPaid ? 'تم اعتماد الفاتورة' : 'تم رفض الفاتورة',
            'body' => $body,
            'url' => route('invoices.show', ['type' => InvoiceTypeEnum::SERVICE->value, 'id' => $this->invoiceId]),
            'icon' => $isPaid ? 'FileText' : 'Undo2',
        ];
    }
}
