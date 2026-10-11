<?php

namespace App\Notifications;

use App\Models\ServiceInvoiceShipment;
use Illuminate\Notifications\Notification;

/**
 * توصيلٌ طُلب بعد اعتماد الفاتورة: عند طلبه لمحاسب الفرع ومديره، وعند البتّ فيه
 * لمن طلبه (اعتُمد / رُفض بسببه).
 */
class ShipmentRequestNotification extends Notification
{
    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public function __construct(
        private readonly ServiceInvoiceShipment $shipment,
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
        $fee = number_format((float) $this->shipment->fee, 2).' ر.س';
        $invoice = $this->shipment->invoice?->invoice_number;
        $url = route('invoices.show', ['type' => 'service', 'id' => $this->shipment->service_invoice_id]);

        return match ($this->event) {
            self::SUBMITTED => [
                'type' => 'shipment_request_submitted',
                'title' => 'طلب توصيل بانتظار الاعتماد',
                'body' => "طلب {$this->shipment->requestedBy?->name} توصيلاً بقيمة {$fee} على الفاتورة {$invoice}.",
                'url' => $url,
                'icon' => 'Truck',
            ],
            self::APPROVED => [
                'type' => 'shipment_request_approved',
                'title' => 'تم اعتماد طلب التوصيل',
                'body' => "اعتُمد التوصيل بقيمة {$fee} على الفاتورة {$invoice}.",
                'url' => $url,
                'icon' => 'ClipboardCheck',
            ],
            self::REJECTED => [
                'type' => 'shipment_request_rejected',
                'title' => 'تم رفض طلب التوصيل',
                'body' => "رُفض طلب التوصيل على الفاتورة {$invoice} — السبب: {$this->reason}",
                'url' => $url,
                'icon' => 'Undo2',
            ],
        };
    }
}
