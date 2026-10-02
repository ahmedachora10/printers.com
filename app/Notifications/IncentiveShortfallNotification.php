<?php

namespace App\Notifications;

use App\Models\IncentivePlan;
use App\Models\ServiceInvoice;
use Illuminate\Notifications\Notification;

/**
 * تاسك 160 — مرتجعٌ أنزل محقَّق خطةٍ صُرفت مكافأتها تحت الهدف. تنبيهٌ للمدير
 * فقط؛ قرار الحسم على الموظف له.
 */
class IncentiveShortfallNotification extends Notification
{
    public function __construct(
        private readonly IncentivePlan $plan,
        private readonly ServiceInvoice $invoice,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $name = $this->plan->user?->name;
        $period = sprintf('%02d/%d', $this->plan->period_month, $this->plan->period_year);

        return [
            'type' => 'incentive_shortfall',
            'title' => 'مرتجع على خطة حوافز مصروفة',
            'body' => "استرجاع الفاتورة {$this->invoice->invoice_number} أنزل مبيعات {$name} لشهر {$period} تحت الهدف بعد صرف المكافأة.",
            'url' => route('incentives.index'),
            'icon' => 'Trophy',
        ];
    }
}
