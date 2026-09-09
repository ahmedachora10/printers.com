<?php

namespace App\Actions\DeliveryZone;

use App\Enums\DeliveryZoneTypeEnum;

/**
 * صفّ الحيّ لا يقيس مسافةً، فحدّاه يُفرغان هنا مرّةً واحدة قبل الحفظ — ولا
 * يُترك ذلك للواجهة: مستخدمٌ يبدّل النوع من «مسافة» إلى «حي» بعد أن كتب الحدود
 * كان سيخزّن صفّ حيٍّ يحمل مدىً لا معنى له، ثم يقرؤه `coversDistance()` يوماً ما.
 */
trait NormalizesZoneRange
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeRange(array $data): array
    {
        $type = $data['type'] instanceof DeliveryZoneTypeEnum
            ? $data['type']
            : DeliveryZoneTypeEnum::tryFrom((string) ($data['type'] ?? ''));

        if ($type !== DeliveryZoneTypeEnum::Distance) {
            $data['from_km'] = null;
            $data['to_km'] = null;

            return $data;
        }

        // السلسلة الفارغة من نموذجٍ لم يُملأ حقلُه ليست صفراً — الشريحة المفتوحة
        // نهايتها `null`، والفرق بينهما هو الفرق بين «حتى صفر» و«بلا نهاية».
        $data['to_km'] = isset($data['to_km']) && $data['to_km'] !== '' ? $data['to_km'] : null;

        return $data;
    }
}
