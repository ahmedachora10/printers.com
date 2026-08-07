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
 * الاشتقاق الوحيد لطبيعة المستند المطبوع: الفاتورة غير المعتمدة (آجل أو مدفوعة
 * جزئياً) ليست مستنداً ضريبياً فتُطبع كعرض سعر — العربون لا يقوم مقام السداد —
 * وبعد اكتمال السداد تصبح فاتورة ضريبية (B2B) إن كان للعميل رقم ضريبي، وإلا
 * فاتورة ضريبية مبسطة (B2C).
 *
 * تستخدمه كل صفحات الطباعة وشاشة عرض الفاتورة — لا تكرّر الشرط في مكان آخر.
 */
export function invoiceDocument(invoice: DocumentSource): InvoiceDocument {
    // المرتجع فاتورة اعتُمدت ثم أُرجعت — ليست عرض سعر ولا مستنداً ضريبياً سارياً.
    if (invoice.status === 'returned') {
        return { title: 'فاتورة مرتجعة', isQuotation: false };
    }

    if (invoice.status !== 'paid') {
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
