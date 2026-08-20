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
     * Ordinal rank used to compare two tiers. The engine no longer treats a
     * lower rank as impossible — the tier follows cumulative spend up *and*
     * down — so this only answers «أترقية هي أم تنزيل؟» for reporting.
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
