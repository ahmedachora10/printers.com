import { BranchIdentity, ThermalBranchHeader } from '@/components/invoices/print-header';
import { cn, formatCurrency, formatDateTime } from '@/lib/utils';
import { type InvoiceBranch } from '@/types/invoice';
import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { useEffect, type ReactNode } from 'react';

interface RefundNotice {
    /** null لمرتجعٍ لم يُرقَّم (لا يحدث بعد ترحيل الترقيم). */
    noticeNumber: string | null;
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
    zatcaQr: string;
}

/**
 * تاسك 150 — «إشعار مرتجع» لصفّ مرتجع واحد. ورقة واحدة للمقاسين: ترويسة A4
 * كترويسة الفاتورة (البيانات يميناً والشعار يساراً)، والحراري يضيّق العرض والخط.
 */
export default function RefundPrint({ notice, format, zatcaQr }: Props) {
    const thermal = format === 'thermal';

    useEffect(() => {
        const timer = setTimeout(() => window.print(), 400);
        return () => clearTimeout(timer);
    }, []);

    const rows: [string, ReactNode][] = [
        ['رقم الإشعار', notice.noticeNumber && <span dir="ltr">{notice.noticeNumber}</span>],
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
            <Head title={`إشعار مرتجع ${notice.noticeNumber ?? notice.invoiceNumber}`} />
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
                {thermal ? (
                    <div className="text-center">
                        <ThermalBranchHeader branch={notice.branch} />
                    </div>
                ) : (
                    <div className="flex items-start justify-between gap-6 border-b-2 border-black pb-6">
                        <div className="space-y-1">
                            <h1 className="text-xl font-bold">{notice.branch.name ?? 'مركز الناسخ للطباعة'}</h1>
                            <BranchIdentity branch={notice.branch} className="space-y-1 text-xs" />
                        </div>
                        {notice.branch.logoUrl && <img src={notice.branch.logoUrl} alt="" className="h-20 w-auto object-contain" />}
                    </div>
                )}
                <h2 className={cn('text-center font-bold', thermal ? 'mt-2 text-sm' : 'my-6 text-lg')}>إشعار مرتجع</h2>

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

                <div className="mt-4 flex justify-center">
                    <QRCodeSVG value={zatcaQr} size={thermal ? 96 : 120} />
                </div>
            </div>
        </div>
    );
}
