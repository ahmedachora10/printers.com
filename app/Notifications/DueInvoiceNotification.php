<?php

namespace App\Notifications;

use App\Enums\InvoiceTypeEnum;
use Illuminate\Notifications\Notification;

class DueInvoiceNotification extends Notification
{
    public function __construct(
        private readonly string $invoiceNumber,
        private readonly int $invoiceId,
        private readonly InvoiceTypeEnum $invoiceType,
        private readonly float $total,
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

        // Service due invoices land in the review queue where an accountant or
        // branch admin settles or cancels them; product invoices link to detail.
        $url = $this->invoiceType === InvoiceTypeEnum::SERVICE
            ? route('invoices.service.review')
            : route('invoices.show', ['type' => $this->invoiceType->value, 'id' => $this->invoiceId]);

        return [
            'type' => 'due_invoice',
            'title' => 'فاتورة آجلة جديدة',
            'body' => "الفاتورة {$this->invoiceNumber} بمبلغ {$total} ر.س مسجلة كآجلة.",
            'url' => $url,
            'icon' => 'FileText',
        ];
    }
}
