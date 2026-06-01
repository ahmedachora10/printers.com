<?php

namespace App\Enums;

enum CommissionSourceTypeEnum: string
{
    case STANDARD = 'standard';
    case REFERRAL_REFERRER = 'referral_referrer';
    case REFERRAL_EXECUTOR = 'referral_executor';

    public function label(): string
    {
        return match ($this) {
            self::STANDARD => 'عمولة قياسية',
            self::REFERRAL_REFERRER => 'عمولة إحالة (المُحيل)',
            self::REFERRAL_EXECUTOR => 'عمولة إحالة (المنفّذ)',
        };
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
