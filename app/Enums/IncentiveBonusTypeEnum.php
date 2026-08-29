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
            // تاسك 73: النسبة تُقاس على الهدف لا على المبيعات المحقّقة.
            self::Percentage => 'نسبة من الهدف',
        };
    }
}
