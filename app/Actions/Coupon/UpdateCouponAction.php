<?php

namespace App\Actions\Coupon;

use App\Models\Coupon;
use Illuminate\Support\Facades\DB;

class UpdateCouponAction
{
    public function handle(Coupon $coupon, array $data): Coupon
    {
        return DB::transaction(function () use ($coupon, $data) {
            if (isset($data['code'])) {
                $data['code'] = strtolower($data['code']);
            }

            $coupon->update($data);

            return $coupon->refresh();
        });
    }
}
