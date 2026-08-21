import InvoiceNotes from '@/components/invoices/invoice-notes';
import { BranchIdentity, ThermalBranchHeader } from '@/components/invoices/print-header';
import { ThermalTotals, printTotals } from '@/components/invoices/print-totals';
import { QUOTATION_DISCLAIMER, formatLineSize, formatLineUnitPrice, invoiceDocument } from '@/lib/invoice';
import { formatCurrency, formatDateTime, formatDateTimeNumeric, formatQty } from '@/lib/utils';
import { type Invoice } from '@/types/invoice';
import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { useEffect } from 'react';

interface Props {
    invoice: Invoice;
    format: 'a4' | 'thermal';
    zatcaQr: string | null;
}

/** تسميات الخصومات في مقاس A4، بترتيب مصفوفة printTotals. */
const A4_DISCOUNT_LABELS = ['خصم الفئة', 'خصم الكوبون', 'خصم المندوب', 'استبدال النقاط'];

function PrintToolbar() {
    return (
        <div className="mb-4 flex items-center justify-end gap-2 print:hidden">
            <button
                type="button"
                onClick={() => window.print()}
                className="bg-primary text-primary-foreground flex items-center gap-1 rounded-md px-3 py-1.5 text-sm"
            >
                <Printer className="size-4" /> طباعة
            </button>
        </div>
    );
}

function ThermalReceipt({ invoice, zatcaQr }: { invoice: Invoice; zatcaQr: string | null }) {
    const doc = invoiceDocument(invoice);
    const hasPayments = (invoice.payments?.length ?? 0) > 0;

    return (
        <div dir="rtl" className="mx-auto max-w-sm bg-white p-4 font-sans text-sm text-black">
            <div className="text-center">
                <ThermalBranchHeader branch={invoice.branch} />
                <h2 className="mt-2 text-sm font-bold">{doc.title}</h2>
                {doc.isQuotation && <p className="mt-1 text-[10px] font-semibold">{QUOTATION_DISCLAIMER}</p>}
            </div>

            <div className="my-3 border-t border-dashed border-black" />

            <div className="space-y-0.5 text-xs">
                <div className="flex justify-between">
                    <span>رقم الفاتورة</span>
                    <span className="font-semibold" dir="ltr">
                        {invoice.invoiceNumber}
                    </span>
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
                {invoice.customerTaxNumber && (
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
                {invoice.deliveryAt && (
                    <div className="flex justify-between font-semibold">
                        <span>موعد التسليم</span>
                        <span dir="ltr">{formatDateTimeNumeric(invoice.deliveryAt)}</span>
                    </div>
                )}
            </div>

            <div className="my-3 border-t border-dashed border-black" />

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
                                {line.notes && <span className="block text-[10px] whitespace-pre-line">{line.notes}</span>}
                                {formatLineSize(line) && <span className="block text-[10px]">المقاس: {formatLineSize(line)}</span>}
                                {line.discountPct > 0 && <span className="block text-[10px]">خصم {line.discountPct}%</span>}
                            </td>
                            <td className="py-1 text-center">{formatQty(line.qty)}</td>
                            <td className="py-1 text-center">{formatLineUnitPrice(line)}</td>
                            <td className="py-1 text-left">{formatCurrency(line.subtotal)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>

            <InvoiceNotes notes={invoice.notes} variant="thermal" />

            <div className="my-3 border-t border-dashed border-black" />

            <div className="space-y-0.5 text-xs">
                <ThermalTotals invoice={invoice} />
                {hasPayments && (
                    <>
                        <div className="flex justify-between">
                            <span>المدفوع (عربون)</span>
                            <span>{formatCurrency(invoice.paidAmount)}</span>
                        </div>
                        <div className="flex justify-between font-bold">
                            <span>المتبقي</span>
                            <span>{formatCurrency(invoice.paymentRemaining)}</span>
                        </div>
                    </>
                )}
            </div>

            {zatcaQr && (
                <div className="my-3 flex justify-center">
                    <QRCodeSVG value={zatcaQr} size={96} />
                </div>
            )}

            <p className="text-center text-xs">شكراً لزيارتكم</p>
        </div>
    );
}

function A4Invoice({ invoice, zatcaQr }: { invoice: Invoice; zatcaQr: string | null }) {
    const totals = printTotals(invoice);
    const doc = invoiceDocument(invoice);
    const hasPayments = (invoice.payments?.length ?? 0) > 0;

    return (
        <div dir="rtl" className="mx-auto max-w-3xl bg-white p-10 font-sans text-sm text-black">
            {/* Header — البيانات يميناً والشعار يساراً (dir=rtl يقلب justify-between). */}
            <div className="flex items-start justify-between gap-6 border-b-2 border-black pb-6">
                <div className="space-y-1">
                    <h1 className="text-xl font-bold">{invoice.branch.name ?? 'مركز الناسخ للطباعة'}</h1>
                    <BranchIdentity branch={invoice.branch} className="space-y-1 text-xs" />
                </div>
                {/* لا صورة مكسورة ولا فراغ: الفرع بلا شعار يكتفي باسمه في الكتلة اليمنى. */}
                {invoice.branch.logoUrl && <img src={invoice.branch.logoUrl} alt="" className="h-20 w-auto object-contain" />}
            </div>

            <div className="my-6 text-center">
                <h2 className="text-lg font-bold">{doc.title}</h2>
                {doc.isQuotation && <p className="mt-1 text-xs font-semibold">{QUOTATION_DISCLAIMER}</p>}
            </div>

            {/* Meta grid */}
            <div className="mb-6 grid grid-cols-2 gap-x-8 gap-y-1 text-sm">
                <div className="flex justify-between">
                    <span className="text-neutral-500">رقم الفاتورة</span>
                    <span className="font-semibold" dir="ltr">
                        {invoice.invoiceNumber}
                    </span>
                </div>
                <div className="flex justify-between">
                    <span className="text-neutral-500">الحالة</span>
                    <span>{invoice.statusLabel}</span>
                </div>
                {invoice.createdAt && (
                    <div className="flex justify-between">
                        <span className="text-neutral-500">التاريخ</span>
                        <span>{formatDateTime(invoice.createdAt)}</span>
                    </div>
                )}
                <div className="flex justify-between">
                    <span className="text-neutral-500">العميل</span>
                    <span>{invoice.customerName ?? 'عميل نقدي'}</span>
                </div>
                {invoice.paymentMethod && (
                    <div className="flex justify-between">
                        <span className="text-neutral-500">طريقة الدفع</span>
                        <span>{invoice.paymentMethod}</span>
                    </div>
                )}
                {invoice.customerTaxNumber && (
                    <div className="flex justify-between">
                        <span className="text-neutral-500">الرقم الضريبي للعميل</span>
                        <span dir="ltr">{invoice.customerTaxNumber}</span>
                    </div>
                )}
                {invoice.deliveryAt && (
                    <div className="flex justify-between font-semibold">
                        <span className="font-normal text-neutral-500">موعد التسليم</span>
                        <span dir="ltr">{formatDateTimeNumeric(invoice.deliveryAt)}</span>
                    </div>
                )}
            </div>

            {/* Lines */}
            <table className="w-full border-collapse text-sm">
                <thead>
                    <tr className="bg-neutral-100">
                        <th className="border border-neutral-300 p-2 text-right">الصنف</th>
                        <th className="border border-neutral-300 p-2 text-center">الكمية</th>
                        <th className="border border-neutral-300 p-2 text-center">السعر</th>
                        <th className="border border-neutral-300 p-2 text-center">الخصم</th>
                        <th className="border border-neutral-300 p-2 text-left">الإجمالي</th>
                    </tr>
                </thead>
                <tbody>
                    {invoice.lines.map((line, i) => (
                        <tr key={i}>
                            <td className="border border-neutral-300 p-2">
                                {line.name}
                                {line.notes && <span className="block text-[10px] whitespace-pre-line text-neutral-500">{line.notes}</span>}
                                {formatLineSize(line) && (
                                    <span className="block text-[10px] text-neutral-500">المقاس: {formatLineSize(line)}</span>
                                )}
                            </td>
                            <td className="border border-neutral-300 p-2 text-center">{formatQty(line.qty)}</td>
                            <td className="border border-neutral-300 p-2 text-center">{formatLineUnitPrice(line)}</td>
                            <td className="border border-neutral-300 p-2 text-center">{line.discountPct > 0 ? `${line.discountPct}%` : '—'}</td>
                            <td className="border border-neutral-300 p-2 text-left">{formatCurrency(line.subtotal)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>

            <InvoiceNotes notes={invoice.notes} variant="a4" />

            {/* Totals + QR */}
            <div className="mt-6 flex items-end justify-between gap-8">
                {zatcaQr ? (
                    <div className="text-center">
                        <QRCodeSVG value={zatcaQr} size={120} />
                        <p className="mt-1 text-[10px] text-neutral-500">رمز الاستجابة الضريبي</p>
                    </div>
                ) : (
                    <div />
                )}

                <div className="w-64 space-y-1">
                    <div className="flex justify-between">
                        <span className="text-neutral-500">المجموع الفرعي</span>
                        <span dir="ltr">{formatCurrency(totals.subtotal)}</span>
                    </div>
                    {totals.discounts.map((discount, i) =>
                        discount > 0 ? (
                            <div key={i} className="flex justify-between">
                                <span className="text-neutral-500">{A4_DISCOUNT_LABELS[i]}</span>
                                <span dir="ltr">−{formatCurrency(discount)}</span>
                            </div>
                        ) : null,
                    )}
                    <div className="flex justify-between">
                        <span className="text-neutral-500">الضريبة ({invoice.vatPct}%)</span>
                        <span dir="ltr">{formatCurrency(totals.vatAmount)}</span>
                    </div>
                    <div className="flex justify-between border-t-2 border-black pt-1 text-base font-bold">
                        <span>الإجمالي</span>
                        <span dir="ltr">{formatCurrency(totals.total)}</span>
                    </div>
                    {/* العربون وما بقي على العميل — تُطبع متى سُجِّلت دفعة على الفاتورة. */}
                    {hasPayments && (
                        <>
                            <div className="flex justify-between">
                                <span className="text-neutral-500">المدفوع (عربون)</span>
                                <span dir="ltr">{formatCurrency(invoice.paidAmount)}</span>
                            </div>
                            <div className="flex justify-between border-t border-black pt-1 font-bold">
                                <span>المتبقي</span>
                                <span dir="ltr">{formatCurrency(invoice.paymentRemaining)}</span>
                            </div>
                        </>
                    )}
                </div>
            </div>

            <p className="mt-10 text-center text-xs text-neutral-500">شكراً لتعاملكم معنا</p>
        </div>
    );
}

export default function InvoicePrint({ invoice, format, zatcaQr }: Props) {
    useEffect(() => {
        const timer = setTimeout(() => window.print(), 400);
        return () => clearTimeout(timer);
    }, []);

    return (
        <div className="bg-white">
            <Head title={`${invoiceDocument(invoice).title} ${invoice.invoiceNumber}`} />
            <div className="mx-auto max-w-3xl px-4 pt-4">
                <PrintToolbar />
            </div>
            {format === 'thermal' ? <ThermalReceipt invoice={invoice} zatcaQr={zatcaQr} /> : <A4Invoice invoice={invoice} zatcaQr={zatcaQr} />}
        </div>
    );
}
