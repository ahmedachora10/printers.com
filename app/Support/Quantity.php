<?php

namespace App\Support;

/**
 * كميات المخزون عشرية منذ تاسك 51، وأكثرها أعداد صحيحة في الواقع (٣ قطع لا
 * ٣٫٠٠). هذا المُنسّق يكتب الرقم كما يُقرأ: يُسقط الأصفار العائدة ويُبقي الكسر
 * حين يكون له معنى — فيقول «3» و«0.5» و«12.25» لا «3.00».
 */
class Quantity
{
    public static function format(float|int|string|null $qty): string
    {
        $value = round((float) $qty, 2);

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
