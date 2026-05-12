<?php

namespace App\Actions\Coupon;

use App\Models\Coupon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DeleteCouponAction
{
    public function handle(Coupon $coupon): bool
    {
        $usedInProductInvoices = Schema::hasTable('product_invoices')
            && DB::table('product_invoices')->where('coupon_id', $coupon->id)->exists();

        $usedInServiceInvoices = Schema::hasTable('service_invoices')
            && DB::table('service_invoices')->where('coupon_id', $coupon->id)->exists();

        if ($usedInProductInvoices || $usedInServiceInvoices) {
            throw ValidationException::withMessages([
                'coupon' => 'لا يمكن حذف كوبون مرتبط بفواتير.',
            ]);
        }

        return DB::transaction(fn () => $coupon->delete());
    }
}
