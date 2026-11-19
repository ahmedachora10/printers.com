<?php

namespace App\Support;

/**
 * تاسك 241 — شرائح التسعير حسب الكمية، على الخدمة وعلى المنتج.
 *
 * الشريحة {to, price, min_price, max_price}: «إلى» الحدّ الأعلى (الأخيرة قد تكون null =
 * «أكثر من»)، و«من» لا تُخزَّن — الشريحة تبدأ مباشرة بعد «إلى» السابقة (جواب العميل:
 * 10.5 م بين «≤10» و«≤20» في الثانية). سعر الشريحة المبلوغة يسري على **كامل** كمية
 * السطر، وكل سطر يُحسب وحده؛ والكمية للمقيس مساحته أو طوله الإجمالي.
 *
 * نظيرها الوحيد في الواجهة tierFor() في resources/js/lib/invoice.ts.
 */
final class PriceTiers
{
    /**
     * الشريحة التي تقع فيها الكمية: أول شريحة الكميةُ ≤ «إلى» فيها، وما فوق آخرها
     * يأخذ آخرها. null بلا شرائح.
     *
     * @param  list<array{to: ?float, price: float, min_price: ?float, max_price: ?float}>|null  $tiers
     * @return array{to: ?float, price: float, min_price: ?float, max_price: ?float}|null
     */
    public static function tierFor(?array $tiers, float $quantity): ?array
    {
        if (empty($tiers)) {
            return null;
        }

        foreach ($tiers as $tier) {
            if ($tier['to'] === null || $quantity <= (float) $tier['to']) {
                return $tier;
            }
        }

        return $tiers[array_key_last($tiers)];
    }

    /**
     * ما يصل من النموذج: صفوف فارغة تُسقط، والأرقام تُقرأ أرقاماً، والترتيب بـ«إلى»
     * (بلا «إلى» آخراً). يُخزَّن هكذا فيقرؤه tierFor() بلا تحويل.
     *
     * @return list<array{to: ?float, price: mixed, min_price: ?float, max_price: ?float}>|null
     */
    public static function normalize(mixed $tiers): ?array
    {
        if (! is_array($tiers)) {
            return null;
        }

        $number = fn ($value) => is_numeric($value) ? round((float) $value, 2) : null;

        $rows = collect($tiers)
            ->filter(fn ($row) => is_array($row) && collect($row)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty())
            ->map(fn (array $row) => [
                'to' => $number($row['to'] ?? null),
                'price' => is_numeric($row['price'] ?? null) ? round((float) $row['price'], 2) : ($row['price'] ?? null),
                'min_price' => $number($row['min_price'] ?? null),
                'max_price' => $number($row['max_price'] ?? null),
            ])
            ->sortBy(fn (array $row) => $row['to'] ?? INF)
            ->values()
            ->all();

        return $rows === [] ? null : $rows;
    }
}
