<?php

namespace App\Notifications;

use App\Enums\PurchaseRequestStatusEnum;
use Illuminate\Notifications\Notification;

/**
 * Tells the requester the outcome of their purchase request: approved, or
 * rejected with the reason the branch admin gave.
 */
class PurchaseRequestDecidedNotification extends Notification
{
    public function __construct(
        private readonly int $requestId,
        private readonly PurchaseRequestStatusEnum $outcome,
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
        $isApproved = $this->outcome === PurchaseRequestStatusEnum::APPROVED;

        return [
            'type' => $isApproved ? 'purchase_request_approved' : 'purchase_request_rejected',
            'title' => $isApproved ? 'تم اعتماد طلب الشراء' : 'تم رفض طلب الشراء',
            'body' => $isApproved
                ? "تم اعتماد طلب الشراء رقم {$this->requestId}."
                : "تم رفض طلب الشراء رقم {$this->requestId}".($this->reason ? " — السبب: {$this->reason}" : '.'),
            'url' => route('purchase-requests.index'),
            'icon' => $isApproved ? 'ClipboardCheck' : 'Undo2',
        ];
    }
}
