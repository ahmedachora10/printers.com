<?php

namespace App\Enums;

/** حالات عرض السعر — «بانتظار الاعتماد» و«معتمد» قرار العميل، يسجّله الموظف. */
enum QuotationStatusEnum: string
{
    case NEW = 'new';
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case CONVERTED = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::NEW => 'جديد',
            self::PENDING => 'بانتظار اعتماد العميل',
            self::APPROVED => 'معتمد من العميل',
            self::REJECTED => 'مرفوض',
            self::CONVERTED => 'تم تحويله إلى فاتورة',
        };
    }
}
