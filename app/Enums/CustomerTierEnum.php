<?php

namespace App\Enums;

enum CustomerTierEnum: string
{
    case None = 'none';
    case Bronze = 'bronze';
    case Silver = 'silver';
    case Gold = 'gold';

    public function label(): string
    {
        return match ($this) {
            self::None => 'بدون',
            self::Bronze => 'برونزي',
            self::Silver => 'فضي',
            self::Gold => 'ذهبي',
        };
    }

    /**
     * Ordinal rank used to compare tiers. Tiers never downgrade, so the
     * loyalty engine only promotes when a newly computed tier outranks the
     * customer's current one.
     */
    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Bronze => 1,
            self::Silver => 2,
            self::Gold => 3,
        };
    }
}
