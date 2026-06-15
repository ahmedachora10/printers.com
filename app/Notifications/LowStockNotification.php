<?php

namespace App\Notifications;

use App\Models\Product;
use Illuminate\Notifications\Notification;

class LowStockNotification extends Notification
{
    public function __construct(
        private readonly Product $product,
        private readonly int $currentStock,
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
            'type' => 'low_stock',
            'title' => 'تنبيه: مخزون منخفض',
            'body' => "وصل مخزون \"{$this->product->name}\" إلى {$this->currentStock} (الحد الأدنى {$this->product->min_stock_level}).",
            'url' => route('inventory.products.index'),
            'icon' => 'Package',
        ];
    }
}
