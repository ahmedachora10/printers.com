<?php

namespace App\Enums;

enum CouponDiscountTypeEnum: string
{
    case Percentage = 'percentage';
    case Fixed      = 'fixed';

    public function label(): string
    {
        return match($this) {
            self::Percentage => 'نسبة مئوية',
            self::Fixed      => 'مبلغ ثابت',
        };
    }
}
