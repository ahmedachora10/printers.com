<?php

namespace App\Enums;

/** تاسك 135 — الاعتماد والتنفيذ نقرةٌ واحدة، فلا حالة «معتمد» وسيطة. */
enum ReturnRequestStatusEnum: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case REJECTED = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'تحت المراجعة',
            self::COMPLETED => 'تم الاسترجاع',
            self::REJECTED => 'مرفوض',
        };
    }
}
