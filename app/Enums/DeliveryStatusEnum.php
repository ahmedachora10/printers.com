<?php

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * حالة موعد تسليم العمل للعميل مقارنةً باليوم. الاشتقاق الوحيد لها هو
 * `forInvoice()` — تستخدمه الموارد وقائمة الفواتير معاً حتى لا يتفرّع المنطق.
 */
enum DeliveryStatusEnum: string
{
    case DELIVERED = 'delivered';
    case OVERDUE = 'overdue';
    case TODAY = 'today';
    case UPCOMING = 'upcoming';

    public function label(): string
    {
        return match ($this) {
            self::DELIVERED => 'تم تسليم العمل',
            self::OVERDUE => 'متأخر عن موعده',
            self::TODAY => 'تسليم اليوم',
            self::UPCOMING => 'قادم',
        };
    }

    /**
     * حالة الموعد لفاتورة: null إن كانت الفاتورة ملغاة أو مرتجعة — فلم يعد لها
     * عمل يُنتظر تسليمه — أو إن لم يُحدَّد موعد ولم تُسلَّم بعد.
     *
     * التسليم الفعلي (`delivered_at`) له الأسبقية على الموعد المتوقَّع: فاتورة
     * سُلّمت متأخرةً تُقرأ «تم تسليم العمل» لا «متأخر عن موعده»، وفاتورة سُلّمت
     * بلا موعد مجدوَل تحمل الحالة كذلك.
     */
    public static function forInvoice(
        ?CarbonInterface $deliveredAt,
        ?CarbonInterface $deliveryAt,
        ?InvoiceStatusEnum $status,
    ): ?self {
        if ($status === InvoiceStatusEnum::CANCELLED || $status === InvoiceStatusEnum::RETURNED) {
            return null;
        }

        if ($deliveredAt !== null) {
            return self::DELIVERED;
        }

        if ($deliveryAt === null) {
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
