<?php

namespace App\Notifications;

use App\Models\Expense;
use Illuminate\Notifications\Notification;

/**
 * تاسك 157 — طلب مصروف من الموظف: عند رفعه لمحاسب الفرع ومديره، وعند البتّ فيه
 * للموظف صاحبه (قُبل / رُفض بسببه).
 */
class ExpenseRequestNotification extends Notification
{
    public const SUBMITTED = 'submitted';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public function __construct(
        private readonly Expense $expense,
        private readonly string $event,
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
        $amount = number_format((float) $this->expense->total, 2).' ر.س';
        $invoice = $this->expense->invoice?->invoice_number;
        $invoiceUrl = route('invoices.show', ['type' => 'service', 'id' => $this->expense->service_invoice_id]);

        return match ($this->event) {
            self::SUBMITTED => [
                'type' => 'expense_request_submitted',
                'title' => 'طلب مصروف جديد',
                'body' => "طلب {$this->expense->requestedBy?->name} تسجيل مصروف {$amount} على الفاتورة {$invoice}.",
                'url' => route('expenses.index', ['approval' => 'requested', 'range' => 'all']),
                'icon' => 'Wallet',
            ],
            self::ACCEPTED => [
                'type' => 'expense_request_accepted',
                'title' => 'تم قبول طلب المصروف',
                'body' => "تم قبول طلب المصروف {$amount} على الفاتورة {$invoice}.",
                'url' => $invoiceUrl,
                'icon' => 'ClipboardCheck',
            ],
            self::REJECTED => [
                'type' => 'expense_request_rejected',
                'title' => 'تم رفض طلب المصروف',
                'body' => "تم رفض طلب المصروف {$amount} على الفاتورة {$invoice} — السبب: {$this->reason}",
                'url' => $invoiceUrl,
                'icon' => 'Undo2',
            ],
        };
    }
}
