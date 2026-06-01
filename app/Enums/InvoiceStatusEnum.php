<?php

namespace App\Enums;

enum InvoiceStatusEnum: string
{
    case PAID = 'paid';
    case DUE = 'due';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PAID => 'مدفوعة',
            self::DUE => 'آجلة',
            self::CANCELLED => 'ملغاة',
        };
    }

    public static function all(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
