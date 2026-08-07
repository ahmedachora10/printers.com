<?php

namespace App\Enums;

enum InvoiceStatusEnum: string
{
    case PAID = 'paid';
    case PARTIALLY_PAID = 'partially_paid';
    case DUE = 'due';
    case CANCELLED = 'cancelled';
    case RETURNED = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::PAID => 'مدفوعة',
            self::PARTIALLY_PAID => 'مدفوعة جزئياً',
            self::DUE => 'غير مسددة',
            self::CANCELLED => 'ملغاة',
            self::RETURNED => 'مرتجع',
        };
    }

    /**
     * الفاتورة التي ما زالت تقبل دفعة جديدة: آجلة أو مدفوعة جزئياً. المدفوعة
     * بالكامل والملغاة والمرتجعة لا يُسجَّل عليها تحصيل.
     */
    public function acceptsPayment(): bool
    {
        return $this === self::DUE || $this === self::PARTIALLY_PAID;
    }

    public function isPaid(): bool
    {
        return $this === self::PAID;
    }

    /**
     * هل تُطبع الفاتورة مستنداً ضريبياً (لا عرض سعر)؟ العربون يُعتبر سداداً: بمجرد
     * قبض دفعة تصير الفاتورة ضريبيةً فتحمل الرقم الضريبي للفرع ورمز ZATCA على
     * كامل قيمتها. الآجلة التي لم يُقبض منها شيء تبقى عرض سعر.
     *
     * هذا هو الاشتقاق الوحيد على الخادم؛ يقابله invoiceDocument() في الواجهة.
     */
    public function isTaxDocument(): bool
    {
        return $this === self::PAID || $this === self::PARTIALLY_PAID;
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
