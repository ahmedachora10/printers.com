import { invoiceTotals } from '@/lib/invoice';
import { formatCurrency } from '@/lib/utils';

/**
 * الحد الأدنى الذي يحتاجه تفكيك الإجمالي — يغطّي الفاتورة المحفوظة وفاتورة
 * نقطة البيع معاً، فكلتاهما تحملان أعمدة الخصومات الأربعة نفسها.
 */
export interface PrintTotalsSource {
    tierDiscountAmount: number;
    couponDiscount: number;
    agentDiscount: number;
    pointsDiscount: number;
    vatPct: number;
    vatAmount: number;
    totalAmount: number;
}

/** تسميات الخصومات بترتيب مصفوفة invoiceTotals. */
const DISCOUNT_LABELS = ['خصم الفئة', 'خصم الكوبون', 'خصم المندوب', 'استبدال النقاط'];

/**
 * تفكيك الإجمالي المعروض على أي ورقة: يعتمد الأرقام المخزّنة على الفاتورة
 * (vat_amount و total_amount) لا إعادة احتساب من مجموع الأسطر، فيوافق المطبوعُ
 * شاشةَ العرض ورمزَ الاستجابة الضريبي.
 */
export function printTotals(invoice: PrintTotalsSource) {
    return invoiceTotals({
        vatPct: invoice.vatPct,
        vatAmount: invoice.vatAmount,
        totalAmount: invoice.totalAmount,
        discounts: [invoice.tierDiscountAmount, invoice.couponDiscount, invoice.agentDiscount, invoice.pointsDiscount],
    });
}

/**
 * كتلة «المجموع الفرعي / الخصومات / الضريبة / الإجمالي» في الإيصال الحراري —
 * مصدر واحد لصفحة طباعة الفاتورة ولإيصالَي نقطة البيع.
 */
export function ThermalTotals({ invoice }: { invoice: PrintTotalsSource }) {
    const totals = printTotals(invoice);

    return (
        <>
            <div className="flex justify-between">
                <span>المجموع الفرعي</span>
                <span>{formatCurrency(totals.subtotal)}</span>
            </div>
            {totals.discounts.map((discount, i) =>
                discount > 0 ? (
                    <div key={i} className="flex justify-between">
                        <span>{DISCOUNT_LABELS[i]}</span>
                        <span>−{formatCurrency(discount)}</span>
                    </div>
                ) : null,
            )}
            <div className="flex justify-between">
                <span>الضريبة ({invoice.vatPct}%)</span>
                <span>{formatCurrency(totals.vatAmount)}</span>
            </div>
            <div className="mt-1 flex justify-between border-t border-black pt-1 text-sm font-bold">
                <span>الإجمالي</span>
                <span>{formatCurrency(totals.total)}</span>
            </div>
        </>
    );
}
