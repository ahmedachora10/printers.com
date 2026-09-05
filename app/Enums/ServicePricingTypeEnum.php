<?php

namespace App\Enums;

enum ServicePricingTypeEnum: string
{
    case Unit = 'unit';
    case Sqm = 'sqm';
    case Linear = 'linear';

    public function label(): string
    {
        return match ($this) {
            self::Unit => 'بالوحدة',
            self::Sqm => 'بالمتر المربع',
            self::Linear => 'بالمتر الطولي',
        };
    }

    /**
     * تسعيرٌ يقيس مقاساً لا يعدّ قطعاً — فسعره سعرُ وحدة قياس (تاسك 55 و80)،
     * وتكلفة خامته للوحدة نفسها (تاسك 63)، وحدّاه يقيسانها (تاسك 64).
     * والفرق بين الحالتين بُعدٌ واحد: المربع يضرب مقاسين والطولي يقيس مقاساً.
     */
    public function isMeasured(): bool
    {
        return $this !== self::Unit;
    }

    /** لاحقة وحدة القياس كما تُكتب بجانب رقم — «م²» أو «م» أو لا شيء. */
    public function unitSuffix(): string
    {
        return match ($this) {
            self::Unit => '',
            self::Sqm => 'م²',
            self::Linear => 'م',
        };
    }
}
