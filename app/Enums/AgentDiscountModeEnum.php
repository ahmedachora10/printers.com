<?php

namespace App\Enums;

enum AgentDiscountModeEnum: string
{
    case Discount = 'discount';
    case Rebate = 'rebate';

    public function label(): string
    {
        return match ($this) {
            self::Discount => 'خصم على الفاتورة',
            self::Rebate => 'عمولة مرتجعة',
        };
    }
}
