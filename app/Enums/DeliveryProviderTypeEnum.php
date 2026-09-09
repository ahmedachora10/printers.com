<?php

namespace App\Enums;

/**
 * تاسك 93 — نوع مزوّد التوصيل: سائقٌ فرد أم شركة توصيل.
 *
 * التسمية `Delivery*` هنا آمنة لأنها على كيانٍ جديد مستقل، بخلاف أعمدة الفاتورة
 * التي تُسمّى `shipping_*` — فـ`delivery_at` و`DeliveryStatusEnum` محجوزتان
 * لموعد **تسليم العمل** للعميل (تاسك 31)، لا لشحنه إليه.
 */
enum DeliveryProviderTypeEnum: string
{
    case Driver = 'driver';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Driver => 'سائق',
            self::Company => 'شركة توصيل',
        };
    }
}
