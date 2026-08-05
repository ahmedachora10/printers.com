<?php

namespace App\Enums;

enum InvoiceStatusEnum: string
{
    case PAID = 'paid';
    case DUE = 'due';
    case CANCELLED = 'cancelled';
    case RETURNED = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::PAID => 'مدفوعة',
            self::DUE => 'غير مسددة',
            self::CANCELLED => 'ملغاة',
            self::RETURNED => 'مرتجع',
        };
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
