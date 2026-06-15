<?php

namespace App\Enums;

enum PurchaseOrderStatusEnum: string
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case PARTIAL = 'partial';
    case RECEIVED = 'received';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'مسودة',
            self::SENT => 'مُرسل',
            self::PARTIAL => 'مستلم جزئياً',
            self::RECEIVED => 'مستلم',
            self::CANCELLED => 'ملغي',
        };
    }

    /** A PO can receive deliveries once it has been issued and until fully received. */
    public function canReceive(): bool
    {
        return in_array($this, [self::SENT, self::PARTIAL], true);
    }

    /** Cancellation is only safe before any stock has been received (ledger is immutable). */
    public function canCancel(): bool
    {
        return in_array($this, [self::DRAFT, self::SENT], true);
    }

    /** Header and lines may only be edited while the PO is still a draft. */
    public function canEdit(): bool
    {
        return $this === self::DRAFT;
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
