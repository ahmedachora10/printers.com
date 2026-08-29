<?php

namespace App\Notifications;

use App\Models\EmployeeDeduction;
use Illuminate\Notifications\Notification;

/**
 * تاسك 74: الموظف يُعلَم بالحسم وبسببه — نظير BonusPaidNotification المعاكس.
 * الحسم بلا إشعارٍ مفاجأةٌ في كشف الراتب.
 */
class DeductionRecordedNotification extends Notification
{
    public function __construct(
        private readonly EmployeeDeduction $deduction,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $amount = number_format((float) $this->deduction->amount, 2);

        return [
            'type' => 'deduction_recorded',
            'title' => 'تم تسجيل حسم عليك',
            'body' => "حسم بمبلغ {$amount} ر.س — السبب: {$this->deduction->reasonLabel()}.",
            'url' => route('dashboard'),
            'icon' => 'TrendingDown',
        ];
    }
}
