<?php

namespace App\Actions\DeliveryZone;

use App\Models\DeliveryZone;
use Illuminate\Support\Facades\DB;

class CreateDeliveryZoneAction
{
    use GuardsZoneOverlap, NormalizesZoneRange;

    /** @param array<string, mixed> $data */
    public function handle(array $data): DeliveryZone
    {
        $data = $this->normalizeRange($data);

        $this->assertNoOverlap($data, (int) $data['branch_id']);

        return DB::transaction(fn () => DeliveryZone::create($data));
    }
}
