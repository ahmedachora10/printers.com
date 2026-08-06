import InvoiceNotes from '@/components/invoices/invoice-notes';
import { QUOTATION_DISCLAIMER, invoiceDocument } from '@/lib/invoice';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import product from '@/routes/pos/product';
import { type PosBranch, type PosInvoice } from '@/types/pos';
import { Head, Link } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useEffect } from 'react';

interface Props {
    invoice: PosInvoice;
    branch: PosBranch;
}

export default function ProductInvoicePrint({ invoice, branch }: Props) {
    const doc = invoiceDocument(invoice);

    useEffect(() => {
        const timer = setTimeout(() => window.print(), 300);
        return () => clearTimeout(timer);
    }, []);

    return (
        <div dir="rtl" className="mx-auto max-w-sm bg-white p-4 font-sans text-sm text-black">
            <Head title={`${doc.title} ${invoice.invoiceNumber}`} />

            {/* Toolbar — hidden when printing */}
            <div className="mb-4 flex items-center justify-between gap-2 print:hidden">
                <Link href={product.create().url} className="rounded-md border px-3 py-1.5 text-sm">
                    فاتورة جديدة
                </Link>
                <button
                    type="button"
                    onClick={() => window.print()}
                    className="bg-primary text-primary-foreground flex items-center gap-1 rounded-md px-3 py-1.5 text-sm"
                >
                    <Printer className="size-4" /> طباعة
                </button>
            </div>

            {/* Header */}
            <div className="text-center">
                <h1 className="text-base font-bold">{branch.name ?? 'مركز الناسخ للطباعة'}</h1>
                {branch.phone && <p className="text-xs">{branch.phone}</p>}
                {branch.address && <p className="text-xs">{branch.address}</p>}
                {branch.taxNumber && <p className="text-xs">الرقم الضريبي: {branch.taxNumber}</p>}
                <h2 className="mt-2 text-sm font-bold">{doc.title}</h2>
                {doc.isQuotation && <p className="mt-1 text-[10px] font-semibold">{QUOTATION_DISCLAIMER}</p>}
            </div>

            <div className="my-3 border-t border-dashed border-black" />

            {/* Meta */}
            <div className="space-y-0.5 text-xs">
                <div className="flex justify-between">
                    <span>رقم الفاتورة</span>
                    <span className="font-semibold">{invoice.invoiceNumber}</span>
                </div>
                {invoice.createdAt && (
                    <div className="flex justify-between">
                        <span>التاريخ</span>
                        <span>{formatDateTime(invoice.createdAt)}</span>
                    </div>
                )}
                <div className="flex justify-between">
                    <span>الحالة</span>
                    <span>{invoice.statusLabel}</span>
                </div>
                {invoice.customerName && (
                    <div className="flex justify-between">
                        <span>العميل</span>
                        <span>{invoice.customerName}</span>
                    </div>
                )}
                {invoice.customerPhone && (
                    <div className="flex justify-between">
                        <span>الهاتف</span>
                        <span>{invoice.customerPhone}</span>
                    </div>
                )}
                {!doc.isQuotation && invoice.customerTaxNumber && (
                    <div className="flex justify-between">
                        <span>الرقم الضريبي للعميل</span>
                        <span dir="ltr">{invoice.customerTaxNumber}</span>
                    </div>
                )}
                {invoice.paymentMethod && (
                    <div className="flex justify-between">
                        <span>طريقة الدفع</span>
                        <span>{invoice.paymentMethod}</span>
                    </div>
                )}
            </div>

            <div className="my-3 border-t border-dashed border-black" />

            {/* Lines */}
            <table className="w-full text-xs">
                <thead>
                    <tr className="border-b border-black">
                        <th className="py-1 text-right font-semibold">الصنف</th>
                        <th className="py-1 text-center font-semibold">كمية</th>
                        <th className="py-1 text-center font-semibold">السعر</th>
                        <th className="py-1 text-left font-semibold">الإجمالي</th>
                    </tr>
                </thead>
                <tbody>
                    {invoice.lines.map((line, i) => (
                        <tr key={i} className="border-b border-dashed border-black/30 align-top">
                            <td className="py-1 text-right">
                                {line.name}
                                {line.discountPct > 0 && <span className="block text-[10px]">خصم {line.discountPct}%</span>}
                            </td>
                            <td className="py-1 text-center">{line.qty}</td>
                            <td className="py-1 text-center">{formatCurrency(line.unitPrice)}</td>
                            <td className="py-1 text-left">{formatCurrency(line.subtotal)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>

            <InvoiceNotes notes={invoice.notes} variant="thermal" />

            <div className="my-3 border-t border-dashed border-black" />

            {/* Totals */}
            <div className="space-y-0.5 text-xs">
                <div className="flex justify-between">
                    <span>المجموع الفرعي</span>
                    <span>{formatCurrency(invoice.subtotal)}</span>
                </div>
                {invoice.tierDiscountAmount > 0 && (
                    <div className="flex justify-between">
                        <span>خصم الفئة</span>
                        <span>−{formatCurrency(invoice.tierDiscountAmount)}</span>
                    </div>
                )}
                {invoice.couponDiscount > 0 && (
                    <div className="flex justify-between">
                        <span>خصم الكوبون</span>
                        <span>−{formatCurrency(invoice.couponDiscount)}</span>
                    </div>
                )}
                {invoice.agentDiscount > 0 && (
                    <div className="flex justify-between">
                        <span>خصم المندوب</span>
                        <span>−{formatCurrency(invoice.agentDiscount)}</span>
                    </div>
                )}
                {invoice.pointsDiscount > 0 && (
                    <div className="flex justify-between">
                        <span>استبدال النقاط</span>
                        <span>−{formatCurrency(invoice.pointsDiscount)}</span>
                    </div>
                )}
                <div className="flex justify-between">
                    <span>الضريبة ({invoice.vatPct}%)</span>
                    <span>{formatCurrency(invoice.vatAmount)}</span>
                </div>
                <div className="mt-1 flex justify-between border-t border-black pt-1 text-sm font-bold">
                    <span>الإجمالي</span>
                    <span>{formatCurrency(invoice.totalAmount)}</span>
                </div>
            </div>

            <div className="my-3 border-t border-dashed border-black" />

            <p className="text-center text-xs">شكراً لزيارتكم</p>
        </div>
    );
}
