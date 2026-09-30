<?php

namespace App\Actions\NetworkDevice;

use App\Models\NetworkDevice;
use Illuminate\Support\Facades\DB;

/** تاسك 146 — حفظ جهاز شبكة. جهازٌ افتراضي واحد لكل فرع: تعيينه يُلغي افتراضية غيره. */
class SaveNetworkDeviceAction
{
    /**
     * @param  array{branch_id: int, payment_method_id: int, name: string, number: string, is_default?: bool, is_active?: bool}  $data
     */
    public function handle(array $data, ?NetworkDevice $device = null): NetworkDevice
    {
        return DB::transaction(function () use ($data, $device) {
            $device ??= new NetworkDevice;
            $device->fill($data)->save();

            if ($device->is_default) {
                NetworkDevice::query()
                    ->where('branch_id', $device->branch_id)
                    ->whereKeyNot($device->id)
                    ->update(['is_default' => false]);
            }

            return $device;
        });
    }
}
