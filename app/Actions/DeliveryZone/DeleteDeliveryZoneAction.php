<?php

namespace App\Actions\DeliveryZone;

use App\Models\DeliveryZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * الشريحة المذكورة على فاتورة — أو على عنوانٍ في دفتر عميل — لا تُحذف.
 */
class DeleteDeliveryZoneAction
{
    public function handle(DeliveryZone $zone): void
    {
        if ($this->isReferenced($zone)) {
            throw ValidationException::withMessages([
                'delivery_zone' => 'لا يمكن حذف الشريحة لأنها مستعملة في فواتير أو عناوين عملاء.',
            ]);
        }

        DB::transaction(fn () => $zone->delete());
    }

    private function isReferenced(DeliveryZone $zone): bool
    {
        if (DB::table('service_invoices')->where('shipping_zone_id', $zone->id)->exists()) {
            return true;
        }

        return DB::table('customer_addresses')->where('delivery_zone_id', $zone->id)->exists();
    }
}
