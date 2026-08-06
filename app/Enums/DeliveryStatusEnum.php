<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * حالة موعد تسليم العمل للعميل مقارنةً باليوم. الاشتقاق الوحيد لها هو
 * `forInvoice()` — تستخدمه الموارد وقائمة الفواتير معاً حتى لا يتفرّع المنطق.
 */
enum DeliveryStatusEnum: string
{
    case OVERDUE = 'overdue';
    case TODAY = 'today';
    case UPCOMING = 'upcoming';

    public function label(): string
    {
        return match ($this) {
            self::OVERDUE => 'متأخر عن موعده',
            self::TODAY => 'تسليم اليوم',
            self::UPCOMING => 'قادم',
        };
    }

    /**
     * حالة الموعد لفاتورة: null إن لم يُحدَّد موعد، أو كانت الفاتورة ملغاة أو
     * مرتجعة — فلم يعد لها عمل يُنتظر تسليمه.
     */
    public static function forInvoice(?CarbonInterface $deliveryAt, ?InvoiceStatusEnum $status): ?self
    {
        if ($deliveryAt === null) {
            return null;
        }

        if ($status === InvoiceStatusEnum::CANCELLED || $status === InvoiceStatusEnum::RETURNED) {
            return null;
        }

        return match (true) {
            $deliveryAt->isToday() => self::TODAY,
            $deliveryAt->isPast() => self::OVERDUE,
            default => self::UPCOMING,
        };
    }

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_map(fn ($case) => $case->value, self::cases());
    }
}
