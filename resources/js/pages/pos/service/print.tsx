import { formatCurrency, formatDateTime } from '@/lib/utils';
import service from '@/routes/pos/service';
import { type PosBranch, type PosInvoice } from '@/types/pos';
import { Head, Link } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useEffect } from 'react';

interface Props {
    invoice: PosInvoice;
    branch: PosBranch;
}

export default function ServiceInvoicePrint({ invoice, branch }: Props) {
    useEffect(() => {
        const timer = setTimeout(() => window.print(), 300);
        return () => clearTimeout(timer);
    }, []);

    return (
        <div dir="rtl" className="mx-auto max-w-sm bg-white p-4 font-sans text-sm text-black">
            <Head title={`فاتورة ${invoice.invoiceNumber}`} />

            {/* Toolbar — hidden when printing */}
            <div className="mb-4 flex items-center justify-between gap-2 print:hidden">
                <Link href={service.create().url} className="rounded-md border px-3 py-1.5 text-sm">
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
                        <th className="py-1 text-right font-semibold">الخدمة</th>
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
                                {line.widthCm != null && line.heightCm != null && (
                                    <span className="block text-[10px]">
                                        المقاس: {line.widthCm}×{line.heightCm} سم
                                    </span>
                                )}
                                {line.discountPct > 0 && <span className="block text-[10px]">خصم {line.discountPct}%</span>}
                            </td>
                            <td className="py-1 text-center">{line.qty}</td>
                            <td className="py-1 text-center">{formatCurrency(line.unitPrice)}</td>
                            <td className="py-1 text-left">{formatCurrency(line.subtotal)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>

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
