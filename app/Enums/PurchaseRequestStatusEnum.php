<?php

namespace App\Enums;

enum PurchaseRequestStatusEnum: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CONVERTED = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'بانتظار الاعتماد',
            self::APPROVED => 'معتمد',
            self::REJECTED => 'مرفوض',
            self::CONVERTED => 'تحوّل لأمر شراء',
        };
    }

    /** Approve/reject is only offered while the request is still pending. */
    public function canDecide(): bool
    {
        return $this === self::PENDING;
    }

    /** Only an approved request may become a purchase order, and only once. */
    public function canConvert(): bool
    {
        return $this === self::APPROVED;
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
