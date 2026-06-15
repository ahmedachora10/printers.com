<?php

namespace App\Notifications;

use App\Models\BonusPayment;
use Illuminate\Notifications\Notification;

class BonusPaidNotification extends Notification
{
    public function __construct(
        private readonly BonusPayment $payment,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $amount = number_format((float) $this->payment->amount, 2);

        return [
            'type' => 'bonus_paid',
            'title' => 'تم صرف مكافأتك',
            'body' => "تم صرف مكافأة تحفيزية بمبلغ {$amount} ر.س.",
            'url' => route('incentives.index'),
            'icon' => 'Trophy',
        ];
    }
}
