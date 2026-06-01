<?php

namespace App\Enums;

enum StockMovementTypeEnum: string
{
    case PURCHASE_IN = 'purchase_in';
    case SALE_OUT = 'sale_out';
    case RETURN_IN = 'return_in';
    case ADJUSTMENT_IN = 'adjustment_in';
    case ADJUSTMENT_OUT = 'adjustment_out';
    case OPENING_STOCK = 'opening_stock';

    public function label(): string
    {
        return match ($this) {
            self::PURCHASE_IN => 'إدخال مشتريات',
            self::SALE_OUT => 'صرف مبيعات',
            self::RETURN_IN => 'إرجاع للمخزون',
            self::ADJUSTMENT_IN => 'تسوية إضافة',
            self::ADJUSTMENT_OUT => 'تسوية خصم',
            self::OPENING_STOCK => 'رصيد افتتاحي',
        };
    }

    /**
     * Inbound types add stock; outbound types remove it.
     * Quantities are persisted signed so current_stock = SUM(qty).
     */
    public function isInbound(): bool
    {
        return in_array($this, [
            self::PURCHASE_IN,
            self::RETURN_IN,
            self::ADJUSTMENT_IN,
            self::OPENING_STOCK,
        ], true);
    }

    public static function all(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
