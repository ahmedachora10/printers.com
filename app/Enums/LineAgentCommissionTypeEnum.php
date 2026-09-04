<?php

namespace App\Enums;

enum LineAgentCommissionTypeEnum: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
    /**
     * لكل وحدة قياس: مترٌ مربع للخدمة بالمربع، ومترٌ طولي للخدمة بالطولي
     * (تاسك 80). القيمة المخزَّنة بقيت `per_sqm` فلا تُعاد كتابة ما بيع.
     */
    case PerSqm = 'per_sqm';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'نسبة مئوية',
            self::Fixed => 'مبلغ ثابت',
            self::PerSqm => 'لكل وحدة قياس',
        };
    }
}
