import { type Invoice, type InvoiceListItem } from '@/types/invoice';
import { type PosInvoice } from '@/types/pos';

type DocumentSource = Pick<Invoice | InvoiceListItem | PosInvoice, 'status' | 'customerTaxNumber'>;

export interface InvoiceDocument {
    /** اسم المستند كما يظهر في الترويسة وفي عنوان الصفحة */
    title: string;
    /** غير معتمدة بعد: مستند غير ملزم ولا يحمل أياً من مقوّمات الفاتورة الضريبية */
    isQuotation: boolean;
}

/**
 * الاشتقاق الوحيد لطبيعة المستند المطبوع: الفاتورة غير المعتمدة (آجل) ليست مستنداً
 * ضريبياً فتُطبع كعرض سعر، وبعد الاعتماد تصبح فاتورة ضريبية (B2B) إن كان للعميل رقم
 * ضريبي، وإلا فاتورة ضريبية مبسطة (B2C).
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
