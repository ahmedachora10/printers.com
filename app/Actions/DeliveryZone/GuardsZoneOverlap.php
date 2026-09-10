<?php

namespace App\Actions\DeliveryZone;

use App\Enums\DeliveryZoneTypeEnum;
use App\Models\DeliveryZone;
use Illuminate\Validation\ValidationException;

/**
 * تاسك 93 — حارس التداخل بين شرائح المسافة.
 *
 * شريحتان نشطتان متداخلتان في فرعٍ واحد (0–5 و3–8) تجعلان الاختيار التلقائي
 * بالمسافة غامضاً: المسافة 4 تقع في كلتيهما، فيتبع السعرُ ترتيبَ الصفوف لا
 * قاعدةً يفهمها أحد. يُرفض الحفظ عند التداخل بدل أن يُترك للمصادفة.
 *
 * الأحياء لا تتداخل بطبيعتها — لا مدى لها تُقاس به — فتمرّ بلا فحص.
 *
 * المدى نصف مفتوح (`from <= d < to`) كما في `DeliveryZone::coversDistance()`،
 * فشريحة 0–5 وشريحة 5–10 **لا** تتداخلان: الخامس يخصّ الثانية وحدها.
 */
trait GuardsZoneOverlap
{
    /**
     * @param  array<string, mixed>  $data
     * @param  DeliveryZone|null  $ignore  الصفّ المُعدَّل — لا يُقاس تداخله مع نفسه
     */
    protected function assertNoOverlap(array $data, int $branchId, ?DeliveryZone $ignore = null): void
    {
        $type = $data['type'] instanceof DeliveryZoneTypeEnum
            ? $data['type']
            : DeliveryZoneTypeEnum::tryFrom((string) ($data['type'] ?? ''));

        if ($type !== DeliveryZoneTypeEnum::Distance) {
            return;
        }

        // الشريحة المعطّلة لا تدخل الاختيار التلقائي، فتداخلها لا يضرّ.
        if (! ($data['is_active'] ?? true)) {
            return;
        }

        $from = (float) ($data['from_km'] ?? 0);
        $to = isset($data['to_km']) && $data['to_km'] !== null && $data['to_km'] !== ''
            ? (float) $data['to_km']
            : null;

        $existing = DeliveryZone::query()
            ->where('branch_id', $branchId)
            ->where('type', DeliveryZoneTypeEnum::Distance->value)
            ->where('is_active', true)
            ->when($ignore !== null, fn ($q) => $q->whereKeyNot($ignore->id))
            ->get();

        foreach ($existing as $zone) {
            $otherFrom = (float) $zone->from_km;
            $otherTo = $zone->to_km !== null ? (float) $zone->to_km : null;

            // مدَيان نصف مفتوحين يتداخلان متى بدأ كلٌّ منهما قبل نهاية الآخر.
            // النهاية الفارغة = ما لا نهاية له.
            $startsBeforeOtherEnds = $otherTo === null || $from < $otherTo;
            $otherStartsBeforeEnds = $to === null || $otherFrom < $to;

            if ($startsBeforeOtherEnds && $otherStartsBeforeEnds) {
                throw ValidationException::withMessages([
                    'from_km' => "المدى يتداخل مع شريحة \"{$zone->name}\" ({$zone->rangeLabel()}). عدّل الحدود أو عطّل تلك الشريحة.",
                ]);
            }
        }
    }
}
