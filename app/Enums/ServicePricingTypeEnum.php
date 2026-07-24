<?php

namespace App\Enums;

enum ServicePricingTypeEnum: string
{
    case Unit = 'unit';
    case Sqm = 'sqm';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'بالوحدة',
            self::Sqm => 'بالمتر المربع',
        };
    }
}
