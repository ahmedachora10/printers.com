<?php

namespace App\Notifications;

use App\Models\AgentPayment;
use Illuminate\Notifications\Notification;

/**
 * Tells an agent that a branch has settled their rebate/commission for a period.
 * An agent may work with several branches, each settling on its own, so the
 * branch name is part of the message — otherwise two payouts read alike.
 */
class AgentCommissionPaidNotification extends Notification
{
    public function __construct(
        private readonly AgentPayment $payment,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $amount = number_format((float) $this->payment->total_rebate, 2);
        $invoices = (int) $this->payment->total_invoices;
        $branch = $this->payment->branch?->name;
        $from = $this->payment->period_start?->format('d/m/Y');
        $to = $this->payment->period_end?->format('d/m/Y');

        $body = $branch
            ? "تم صرف عمولتك من فرع {$branch} بمبلغ {$amount} ر.س عن {$invoices} فاتورة خلال الفترة {$from} إلى {$to}."
            : "تم صرف عمولتك بمبلغ {$amount} ر.س عن {$invoices} فاتورة خلال الفترة {$from} إلى {$to}.";

        return [
            'type' => 'agent_commission_paid',
            'title' => 'تم صرف عمولتك',
            'body' => $body,
            // The agent portal is the only screen an agent may open; /agent-payments
            // is gated away from the agent role.
            'url' => route('agent-portal.index'),
            'icon' => 'Wallet',
        ];
    }
}
