<?php

namespace App\Notifications;

use App\Models\CommissionPayment;
use Illuminate\Notifications\Notification;

class CommissionPaidNotification extends Notification
{
    public function __construct(
        private readonly CommissionPayment $payment,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $amount = number_format((float) $this->payment->total_amount, 2);
        $from = $this->payment->period_start?->format('Y-m-d');
        $to = $this->payment->period_end?->format('Y-m-d');

        return [
            'type' => 'commission_paid',
            'title' => 'تم صرف عمولتك',
            'body' => "تم صرف عمولة بمبلغ {$amount} ر.س عن الفترة {$from} إلى {$to}.",
            'url' => route('commissions.index'),
            'icon' => 'Wallet',
        ];
    }
}
