<?php

namespace App\Actions\Coupon;

use App\Models\Coupon;
use Illuminate\Support\Facades\DB;

class CreateCouponAction
{
    public function handle(array $data): Coupon
    {
        return DB::transaction(function () use ($data) {
            $data['code'] = strtolower($data['code']);

            return Coupon::create($data);
        });
    }
}
