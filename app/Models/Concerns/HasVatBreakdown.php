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

    /** رسم التوصيل إن كان لهذا النوع رسم؛ فاتورة المنتجات بلا عمود فتقرأ صفراً. */
    public function shippingFee(): float
    {
        return round((float) ($this->shipping_fee ?? 0), 2);
    }

    /**
     * قيمة **البيع** صافيةً من الضريبة ومن رسم التوصيل — أساس نقاط الولاء
     * والإنفاق التراكمي (تاسك 93).
     *
     * مالُ الشحن ليس بيعاً يُكافأ عليه: لا يكسب العميل نقاطاً على أجرة السائق
     * ولا تقرّبه من الفئة الذهبية.
     *
     * الاشتقاق هو معادلة `$servicesNet` في `CalculateServiceInvoiceAction`
     * حرفياً — القسمة على النسبة بعد طرح الشحن — فلا ينحرف عنها بقرش. وعلى
     * فاتورةٍ بلا شحن يساوي `netAmount()` تماماً، فالفواتير القائمة كلّها تُقرأ
     * كما كانت.
     */
    public function salesNetAmount(): float
    {
        $sales = (float) $this->total_amount - $this->shippingFee();

        return round($sales / (1 + (float) $this->vat_pct / 100), 2);
    }
}
