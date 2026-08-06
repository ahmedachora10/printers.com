<?php

namespace App\Notifications;

use App\Enums\InvoiceTypeEnum;
use Illuminate\Notifications\Notification;

/**
 * تذكير الموظف صاحب فاتورة الخدمة بموعد تسليم عملها غداً. يُرسله الأمر المجدول
 * `invoices:notify-upcoming-deliveries` مرة واحدة لكل فاتورة.
 */
class UpcomingDeliveryNotification extends Notification
{
    public function __construct(
        private readonly string $invoiceNumber,
        private readonly int $invoiceId,
        private readonly string $deliveryAt,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'upcoming_delivery',
            // المعرّف هو ما يمنع تكرار التذكير لو أُعيد تشغيل الأمر في اليوم نفسه.
            'invoiceId' => $this->invoiceId,
            'title' => 'تذكير: موعد تسليم غداً',
            'body' => "موعد تسليم عمل الفاتورة {$this->invoiceNumber} غداً {$this->deliveryAt}.",
            'url' => route('invoices.show', ['type' => InvoiceTypeEnum::SERVICE->value, 'id' => $this->invoiceId]),
            'icon' => 'CalendarClock',
        ];
    }
}
