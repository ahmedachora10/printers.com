<?php

namespace App\Enums;

enum CustomerTypeEnum: string
{
    case Individual = 'individual';
    case Corporate  = 'corporate';

    public function label(): string
    {
        return match($this) {
            self::Individual => 'فردي',
            self::Corporate  => 'مؤسسة',
        };
    }
}
