<?php

namespace App\Enums;

/**
 * تاسك 93 — الشريحة نوعان في جدولٍ واحد، لأن العميل طلب «الاثنين معاً»:
 *
 *  - `Area`     — حيٌّ باسمه: «حي النرجس» بـ25 ر.س. يُختار بالاسم، ولا تُقاس
 *                 عليه مسافةٌ أبداً، و`from_km`/`to_km` فارغان فيه.
 *  - `Distance` — مدىً بالكيلومترات: «من 0 إلى 5 كم». يُختار بالاسم أو تُكتب
 *                 المسافة فيُنتقى وحده. `to_km` الفارغ يعني شريحةً مفتوحة
 *                 («أكثر من 20 كم»).
 *
 * جدولٌ واحد لا جدولان: كلاهما «سببٌ يحدّد سعر توصيل»، والفصل كان سيكرّر
 * الحقول والسياسة والشاشة مرّتين بلا فرقٍ في السلوك.
 */
enum DeliveryZoneTypeEnum: string
{
    case Area = 'area';
    case Distance = 'distance';

    public function label(): string
    {
        return match ($this) {
            self::Area => 'حي',
            self::Distance => 'مسافة',
        };
    }

    /** هل يقيس هذا النوع مسافةً بالكيلومترات؟ */
    public function isMeasured(): bool
    {
        return $this === self::Distance;
    }
}
