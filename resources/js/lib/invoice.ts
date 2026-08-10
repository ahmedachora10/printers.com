import { type Invoice, type InvoiceListItem } from '@/types/invoice';
import { type PosInvoice } from '@/types/pos';

type DocumentSource = Pick<Invoice | InvoiceListItem | PosInvoice, 'status' | 'customerTaxNumber'>;

export interface InvoiceDocument {
    /** اسم المستند كما يظهر في الترويسة وفي عنوان الصفحة */
    title: string;
    /** غير معتمدة بعد: مستند غير ملزم ولا يحمل أياً من مقوّمات الفاتورة الضريبية */
    isQuotation: boolean;
}

/** ألوان بادج الحالة — مصدر واحد لقائمة الفواتير وشاشة عرضها. */
export const INVOICE_STATUS_COLORS: Record<string, string> = {
    paid: 'border-green-200 bg-green-50 text-green-700',
    partially_paid: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300',
    due: 'border-red-200 bg-red-50 text-red-700',
    cancelled: 'border-border bg-muted/60 text-muted-foreground',
    returned: 'border-red-300 bg-red-100 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-300',
};

/**
 * الاشتقاق الوحيد لطبيعة المستند المطبوع: الفاتورة الآجلة التي لم يُقبض منها شيء
 * ليست مستنداً ضريبياً فتُطبع كعرض سعر. أما العربون فيُعتبر سداداً — بمجرد قبض
 * دفعة تصير الفاتورة ضريبية (B2B) إن كان للعميل رقم ضريبي، وإلا فاتورة ضريبية
 * مبسطة (B2C)، على كامل قيمة الفاتورة لا على المقبوض.
 *
 * تستخدمه كل صفحات الطباعة وشاشة عرض الفاتورة — لا تكرّر الشرط في مكان آخر.
 * يقابله InvoiceStatusEnum::isTaxDocument() على الخادم.
 */
export function invoiceDocument(invoice: DocumentSource): InvoiceDocument {
    // المرتجع فاتورة اعتُمدت ثم أُرجعت — ليست عرض سعر ولا مستنداً ضريبياً سارياً.
    if (invoice.status === 'returned') {
        return { title: 'فاتورة مرتجعة', isQuotation: false };
    }

    if (invoice.status !== 'paid' && invoice.status !== 'partially_paid') {
        return { title: 'عرض سعر', isQuotation: true };
    }

    return {
        title: invoice.customerTaxNumber ? 'فاتورة ضريبية' : 'فاتورة ضريبية مبسطة',
        isQuotation: false,
    };
}

export function invoiceDocumentTitle(invoice: DocumentSource): string {
    return invoiceDocument(invoice).title;
}

/** التنويه المطبوع أسفل عرض السعر. */
export const QUOTATION_DISCLAIMER = 'هذا العرض غير ملزم ولا يُعد فاتورة ضريبية';

export interface TotalsBreakdownInput {
    /** نسبة الضريبة المطبَّقة على هذه الفاتورة */
    vatPct: number;
    /** مبلغ الضريبة كما احتسبه الخادم (أو معاينة نقطة البيع) */
    vatAmount: number;
    /** الإجمالي الذي يدفعه العميل — شامل الضريبة */
    totalAmount: number;
    /** الخصومات كما تُطرح فعلاً من فاتورة العميل (شاملة للضريبة)، بترتيب العرض */
    discounts: number[];
}

export interface TotalsBreakdown {
    /** المجموع الفرعي صافياً من الضريبة، قبل خصومات الفاتورة */
    subtotal: number;
    /** الخصومات نفسها صافيةً من الضريبة، بنفس ترتيب المدخل */
    discounts: number[];
    vatAmount: number;
    /** الإجمالي شامل الضريبة = ما يدفعه العميل */
    total: number;
}

const round2 = (value: number): number => Math.round(value * 100) / 100;

/**
 * الاشتقاق الوحيد لعرض «المجموع الفرعي / الضريبة / الإجمالي» في كل شاشة تعرض
 * فاتورة أو تعاينها. الأسعار المُدخلة في نقطة البيع شاملة للضريبة، فالإجمالي هو
 * ما يدفعه العميل والضريبة مستخرَجة من داخله — لذا يُعرض المجموع الفرعي صافياً
 * من الضريبة، وتُعرض الخصومات صافيةً منها كذلك حتى يجمع العمود بالقرش:
 *
 *   المجموع الفرعي − الخصومات + الضريبة = الإجمالي
 *
 * المجموع الفرعي مشتقّ من (الإجمالي − الضريبة) مضافاً إليه الخصومات الصافية،
 * لا من مجموع الأسطر، حتى لا يُنتج التقريب فرق قرش في العمود.
 */
export function invoiceTotals({ vatPct, vatAmount, totalAmount, discounts }: TotalsBreakdownInput): TotalsBreakdown {
    const netDiscounts = discounts.map((discount) => round2(discount / (1 + vatPct / 100)));
    const net = round2(totalAmount - vatAmount);

    return {
        subtotal: round2(net + netDiscounts.reduce((sum, discount) => sum + discount, 0)),
        discounts: netDiscounts,
        vatAmount,
        total: totalAmount,
    };
}
