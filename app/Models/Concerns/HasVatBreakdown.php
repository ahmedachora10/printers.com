<?php

namespace App\Models\Concerns;

/**
 * أسعار نقطة البيع شاملةٌ للضريبة (تاسك 37): الإجمالي هو ما يدفعه العميل،
 * والضريبة جزءٌ منه لا إضافةٌ عليه. فالصافي يُشتقّ بالطرح دائماً — لا بقسمة
 * الإجمالي على النسبة — كي يبقى متطابقاً مع ما خُزّن في vat_amount.
 */
trait HasVatBreakdown
{
    /** قيمة الفاتورة صافيةً من ضريبة القيمة المضافة. */
    public function netAmount(): float
    {
        return round((float) $this->total_amount - (float) $this->vat_amount, 2);
    }
}
