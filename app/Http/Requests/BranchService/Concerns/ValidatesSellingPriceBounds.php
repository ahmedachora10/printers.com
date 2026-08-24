<?php

namespace App\Http\Requests\BranchService\Concerns;

/**
 * حدّا سعر بيع الخدمة (تاسك 64)، مشتركان بين طلبَي الإنشاء والتحديث فلا يفترقان.
 *
 * الحدّان اختياريان وكلٌّ منهما مستقلّ: فارغُ السقف = مفتوح من الأعلى، وفارغ
 * الأرضية = مفتوح من الأسفل. والقيد الوحيد بينهما ألّا تعلو الأرضية السقفَ —
 * وإلا صارت الخدمة غير قابلة للبيع بأي رقم، وهو خطأ يُكتشف في نقطة البيع لا في
 * الشاشة التي صنعته.
 */
trait ValidatesSellingPriceBounds
{
    /** @return array<int, string> */
    protected function sellingPriceFloorRules(): array
    {
        $cap = $this->input('max_selling_price');

        // السقف صفراً أو فارغاً لا يُقرأ سقفاً (نفس قراءة assertPriceWithinCap)،
        // فلا تُقاس الأرضية إليه.
        return is_numeric($cap) && (float) $cap > 0 ? ['lte:max_selling_price'] : [];
    }

    /** @return array<string, string> */
    protected function sellingPriceBoundMessages(): array
    {
        return [
            'min_selling_price.lte' => 'أقل سعر للبيع لا يجوز أن يتجاوز أعلى سعر — لن تكون الخدمة قابلة للبيع بأي رقم.',
        ];
    }
}
