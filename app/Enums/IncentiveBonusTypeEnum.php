<?php

namespace App\Enums;

enum IncentiveBonusTypeEnum: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';

    public function label(): string
    {
        return match ($this) {
            self::Fixed => 'مبلغ ثابت',
            self::Percentage => 'نسبة من المبيعات',
        };
    }
}
