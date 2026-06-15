<?php

namespace App\Enums;

enum AgentTypeEnum: string
{
    case Individual = 'individual';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'فرد',
            self::Company => 'شركة',
        };
    }
}
