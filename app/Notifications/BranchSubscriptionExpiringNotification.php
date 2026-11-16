<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * تاسك 228: تنبيه مدير الفرع باقتراب نهاية الاشتراك — قبل 30 و15 و7 أيام. يُرسله
 * الأمر المجدول `branches:notify-subscription-expiry` مرة واحدة لكل عتبة.
 */
class BranchSubscriptionExpiringNotification extends Notification
{
    public function __construct(
        private readonly int $branchId,
        private readonly string $branchName,
        private readonly int $daysLeft,
        private readonly string $endsAt,
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
            'type' => 'branch_subscription_expiring',
            // الفرع + تاريخ النهاية + العتبة هي ما يمنع التكرار؛ والتجديد (نهاية جديدة) يعيد التنبيهات.
            'branchId' => $this->branchId,
            'endsAt' => $this->endsAt,
            'daysLeft' => $this->daysLeft,
            'title' => 'اقتراب انتهاء اشتراك الفرع',
            'body' => "يتبقّى {$this->daysLeft} يوماً على انتهاء اشتراك فرع {$this->branchName} (".date('d/m/Y', strtotime($this->endsAt)).'). تواصل مع الإدارة للتجديد.',
            'url' => route('dashboard'),
            'icon' => 'CalendarClock',
        ];
    }
}
