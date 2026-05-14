<?php

namespace App\Enums;

enum CustomerTierEnum: string
{
    case None   = 'none';
    case Bronze = 'bronze';
    case Silver = 'silver';
    case Gold   = 'gold';

    public function label(): string
    {
        return match($this) {
            self::None   => 'بدون',
            self::Bronze => 'برونزي',
            self::Silver => 'فضي',
            self::Gold   => 'ذهبي',
        };
    }
}
