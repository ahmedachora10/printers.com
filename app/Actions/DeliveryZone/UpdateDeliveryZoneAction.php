<?php

namespace App\Actions\DeliveryZone;

use App\Models\DeliveryZone;
use Illuminate\Support\Facades\DB;

class UpdateDeliveryZoneAction
{
    use GuardsZoneOverlap, NormalizesZoneRange;

    /** @param array<string, mixed> $data */
    public function handle(DeliveryZone $zone, array $data): DeliveryZone
    {
        // التبديل السريع للحالة يمرّ بهذا المسار بحقلٍ واحد، فيُكمَّل من الصفّ
        // القائم قبل الفحص وإلا قُرئ النوع فارغاً وأُفرغت الحدود بلا سبب.
        $data = $this->normalizeRange([
            'type' => $data['type'] ?? $zone->type,
            'from_km' => array_key_exists('from_km', $data) ? $data['from_km'] : $zone->from_km,
            'to_km' => array_key_exists('to_km', $data) ? $data['to_km'] : $zone->to_km,
            'is_active' => $data['is_active'] ?? $zone->is_active,
        ] + $data);

        $this->assertNoOverlap($data, (int) $zone->branch_id, $zone);

        return DB::transaction(function () use ($zone, $data) {
            $zone->update($data);

            return $zone;
        });
    }
}
