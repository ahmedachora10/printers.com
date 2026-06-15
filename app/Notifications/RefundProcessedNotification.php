<?php

namespace App\Notifications;

use App\Models\Refund;
use Illuminate\Notifications\Notification;

class RefundProcessedNotification extends Notification
{
    public function __construct(
        private readonly Refund $refund,
        private readonly ?string $invoiceNumber,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $amount = number_format((float) $this->refund->amount, 2);
        $invoice = $this->invoiceNumber ? " للفاتورة {$this->invoiceNumber}" : '';

        return [
            'type' => 'refund',
            'title' => 'تم تسجيل مرتجع',
            'body' => "تم تسجيل مرتجع بمبلغ {$amount} ر.س{$invoice}.",
            'url' => route('refunds.index'),
            'icon' => 'Undo2',
        ];
    }
}
