<?php

namespace App\Actions\DeliveryZone;

use App\Models\DeliveryZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * الشريحة المذكورة على فاتورة لا تُحذف — الفاتورة تعرض شريحتها في بيان التوصيل.
 * العمودان يصلان في الكوميتين التاليين، فيبقى الحارس صامتاً حتى ذلك الحين.
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
        if (Schema::hasColumn('service_invoices', 'shipping_zone_id')
            && DB::table('service_invoices')->where('shipping_zone_id', $zone->id)->exists()) {
            return true;
        }

        return Schema::hasTable('customer_addresses')
            && DB::table('customer_addresses')->where('delivery_zone_id', $zone->id)->exists();
    }
}
