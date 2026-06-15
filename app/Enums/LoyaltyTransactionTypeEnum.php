<?php

namespace App\Enums;

enum LoyaltyTransactionTypeEnum: string
{
    case Earn = 'earn';
    case Redeem = 'redeem';
    case ManualAdjust = 'manual_adjust';
    case Expire = 'expire';

    public function label(): string
    {
        return match ($this) {
            self::Earn => 'اكتساب',
            self::Redeem => 'استبدال',
            self::ManualAdjust => 'تعديل يدوي',
            self::Expire => 'انتهاء صلاحية',
        };
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
