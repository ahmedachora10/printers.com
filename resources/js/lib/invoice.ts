import { type Invoice, type InvoiceListItem } from '@/types/invoice';

type TitleSource = Pick<Invoice | InvoiceListItem, 'status' | 'customerTaxNumber'>;

/**
 * اسم المستند المطبوع: الفاتورة غير المعتمدة (آجل) ليست مستنداً ضريبياً فتُسمى عرض سعر،
 * وبعد الاعتماد تصبح فاتورة ضريبية (B2B) إن كان للعميل رقم ضريبي، وإلا فاتورة ضريبية مبسطة (B2C).
 */
export function invoiceDocumentTitle(invoice: TitleSource): string {
    if (invoice.status !== 'paid') {
        return 'عرض سعر';
    }

    return invoice.customerTaxNumber ? 'فاتورة ضريبية' : 'فاتورة ضريبية مبسطة';
}
