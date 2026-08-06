<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Tells the branch admin and the accountant that a new internal purchase
 * request is waiting for a decision.
 */
class PurchaseRequestSubmittedNotification extends Notification
{
    public function __construct(
        private readonly int $requestId,
        private readonly string $requesterName,
        private readonly int $linesCount,
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
            'type' => 'purchase_request_submitted',
            'title' => 'طلب شراء جديد',
            'body' => "قدّم {$this->requesterName} طلب شراء رقم {$this->requestId} يحتوي {$this->linesCount} صنفاً.",
            'url' => route('purchase-requests.index'),
            'icon' => 'ShoppingBasket',
        ];
    }
}
