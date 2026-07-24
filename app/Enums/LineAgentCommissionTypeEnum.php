<?php

namespace App\Enums;

enum LineAgentCommissionTypeEnum: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
    case PerSqm = 'per_sqm';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'نسبة مئوية',
            self::Fixed => 'مبلغ ثابت',
            self::PerSqm => 'لكل متر مربع',
        };
    }
}
