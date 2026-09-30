import { ThermalBranchHeader } from '@/components/invoices/print-header';
import { cn, formatCurrency, formatDateTime } from '@/lib/utils';
import { type InvoiceBranch } from '@/types/invoice';
import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { useEffect, type ReactNode } from 'react';

interface RefundNotice {
    invoiceNumber: string;
    invoiceDate: string | null;
    customerName: string | null;
    customerPhone: string | null;
    refundedAt: string | null;
    amount: number;
    vatAmount: number;
    netAmount: number;
    reason: string;
    paymentMethodName: string | null;
    userName: string | null;
    branch: InvoiceBranch;
}

interface Props {
    notice: RefundNotice;
    format: 'a4' | 'thermal';
}

/**
 * تاسك 150 — «إشعار مرتجع» لصفّ مرتجع واحد. ورقة واحدة للمقاسين: الحراري
 * يضيّق العرض والخط فقط، فالمحتوى صفوفُ «تسمية — قيمة» في الحالتين.
 */
export default function RefundPrint({ notice, format }: Props) {
    const thermal = format === 'thermal';

    useEffect(() => {
        const timer = setTimeout(() => window.print(), 400);
        return () => clearTimeout(timer);
    }, []);

    const rows: [string, ReactNode][] = [
        ['رقم الفاتورة', <span dir="ltr">{notice.invoiceNumber}</span>],
        ['تاريخ الفاتورة', notice.invoiceDate ? formatDateTime(notice.invoiceDate) : '—'],
        ['تاريخ المرتجع', notice.refundedAt ? formatDateTime(notice.refundedAt) : '—'],
        ['العميل', notice.customerName ?? 'عميل نقدي'],
        ['الجوال', notice.customerPhone && <span dir="ltr">{notice.customerPhone}</span>],
        ['طريقة الردّ', notice.paymentMethodName ?? '—'],
        ['المنفّذ', notice.userName ?? '—'],
        ['السبب', notice.reason],
    ];

    return (
        <div className="bg-white">
            <Head title={`إشعار مرتجع ${notice.invoiceNumber}`} />
            <div className="mx-auto flex max-w-3xl justify-end px-4 pt-4 print:hidden">
                <button
                    type="button"
                    onClick={() => window.print()}
                    className="bg-primary text-primary-foreground flex items-center gap-1 rounded-md px-3 py-1.5 text-sm"
                >
                    <Printer className="size-4" /> طباعة
                </button>
            </div>

            <div dir="rtl" className={cn('mx-auto bg-white font-sans text-black', thermal ? 'max-w-sm p-4 text-xs' : 'max-w-3xl p-10 text-sm')}>
                <div className="text-center">
                    <ThermalBranchHeader branch={notice.branch} />
                    <h2 className={cn('mt-3 font-bold', thermal ? 'text-sm' : 'text-lg')}>إشعار مرتجع</h2>
                </div>

                <div className="my-3 border-t border-dashed border-black" />

                <div className="space-y-1">
                    {rows.map(
                        ([label, value]) =>
                            value && (
                                <div key={label} className="flex justify-between gap-4">
                                    <span>{label}</span>
                                    <span className="text-left">{value}</span>
                                </div>
                            ),
                    )}
                </div>

                <div className="my-3 border-t border-dashed border-black" />

                <div className="space-y-1">
                    <div className="flex justify-between">
                        <span>المبلغ قبل الضريبة</span>
                        <span>{formatCurrency(notice.netAmount)}</span>
                    </div>
                    <div className="flex justify-between">
                        <span>الضريبة</span>
                        <span>{formatCurrency(notice.vatAmount)}</span>
                    </div>
                    <div className={cn('flex justify-between font-bold', !thermal && 'text-base')}>
                        <span>المبلغ المرتجع</span>
                        <span>{formatCurrency(notice.amount)}</span>
                    </div>
                </div>
            </div>
        </div>
    );
}
