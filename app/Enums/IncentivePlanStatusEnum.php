<?php

namespace App\Enums;

enum IncentivePlanStatusEnum: string
{
    case Active = 'active';
    case Achieved = 'achieved';
    case Missed = 'missed';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'قيد التنفيذ',
            self::Achieved => 'تم تحقيقه',
            self::Missed => 'لم يتحقق',
            self::Paid => 'تم الصرف',
        };
    }
}
