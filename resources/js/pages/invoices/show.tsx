import { DataTable, type ColumnDef } from '@/components/data-table';
import DeliveryBadge from '@/components/invoices/delivery-badge';
import InvoiceNotes from '@/components/invoices/invoice-notes';
import RecordPaymentModal, { type PaymentMethodOption } from '@/components/invoices/record-payment-modal';
import RefundFormModal from '@/components/refunds/refund-form-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/app-layout';
import { INVOICE_STATUS_COLORS, invoiceDocumentTitle } from '@/lib/invoice';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import serviceInvoice from '@/routes/invoices/service';
import posService from '@/routes/pos/service';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type Invoice } from '@/types/invoice';
import { Head, router, usePage } from '@inertiajs/react';
import { Ban, CheckCircle2, Paperclip, Pencil, Printer, ReceiptText, Undo2, Wallet } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

interface Props {
    invoice: Invoice;
    paymentMethodOptions: PaymentMethodOption[];
}

type InvoiceLine = Invoice['lines'][number];

const lineColumns: ColumnDef<InvoiceLine>[] = [
    {
        key: 'name',
        header: 'الصنف',
        className: 'font-medium',
        cell: (line) => (
            <>
                {line.name}
                {line.notes && <span className="text-muted-foreground block text-xs whitespace-pre-line">{line.notes}</span>}
                {line.sku && (
                    <span className="text-muted-foreground block text-xs" dir="ltr">
                        {line.sku}
                    </span>
                )}
                {line.widthCm != null && line.heightCm != null && (
                    <span className="text-muted-foreground block text-xs">
                        الأبعاد: {line.widthCm}×{line.heightCm} سم (
                        {Math.round(((line.widthCm / 100) * (line.heightCm / 100) + Number.EPSILON) * 100) / 100} م²)
                    </span>
                )}
                {line.lineAgentName && (
                    <span className="block text-xs text-sky-700 dark:text-sky-400">
                        صاحب العمولة: {line.lineAgentName}
                        {line.lineAgentCommissionAmount != null && line.lineAgentCommissionAmount > 0 && (
                            <> — {formatCurrency(line.lineAgentCommissionAmount)}</>
                        )}
                    </span>
                )}
            </>
        ),
    },
    { key: 'qty', header: 'الكمية', headerClassName: 'text-center', className: 'text-center tabular-nums', cell: (line) => line.qty },
    {
        key: 'unitPrice',
        header: 'السعر',
        headerClassName: 'text-center',
        className: 'text-center tabular-nums',
        cell: (line) => <span dir="ltr">{formatCurrency(line.unitPrice)}</span>,
    },
    {
        key: 'discountPct',
        header: 'الخصم',
        headerClassName: 'text-center',
        className: 'text-center tabular-nums',
        cell: (line) => (line.discountPct > 0 ? `${line.discountPct}%` : '—'),
    },
    {
        key: 'subtotal',
        header: 'الإجمالي',
        headerClassName: 'text-end',
        className: 'text-end tabular-nums font-medium',
        cell: (line) => <span dir="ltr">{formatCurrency(line.subtotal)}</span>,
    },
];

function MetaRow({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-4 py-1">
            <span className="text-muted-foreground text-sm">{label}</span>
            <span className="text-sm font-medium">{value}</span>
        </div>
    );
}

function TotalRow({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className={`flex items-center justify-between ${strong ? 'text-base font-bold' : 'text-sm'}`}>
            <span className={strong ? '' : 'text-muted-foreground'}>{label}</span>
            <span className="tabular-nums" dir="ltr">
                {value}
            </span>
        </div>
    );
}

export default function InvoiceShow({ invoice, paymentMethodOptions }: Props) {
    const { props } = usePage<SharedData>();
    const [refundOpen, setRefundOpen] = useState(false);
    const [paymentOpen, setPaymentOpen] = useState(false);
    const [approveOpen, setApproveOpen] = useState(false);
    const [approving, setApproving] = useState(false);
    const [returnOpen, setReturnOpen] = useState(false);
    const [returnReason, setReturnReason] = useState('');
    const [returning, setReturning] = useState(false);

    useEffect(() => {
        if (props.success) {
            toast.success(props.success as string);
        }
    }, [props.success]);

    function confirmApprove() {
        setApproving(true);
        router.patch(
            serviceInvoice.pay(invoice.id).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setApproving(false);
                    setApproveOpen(false);
                },
            },
        );
    }

    function confirmReturn() {
        setReturning(true);
        router.post(
            posService.return(invoice.id).url,
            { reason: returnReason.trim() },
            {
                onError: (e) => {
                    toast.error((Object.values(e)[0] as string) ?? 'تعذّر استرجاع الفاتورة.');
                    setReturning(false);
                    setReturnOpen(false);
                },
                onFinish: () => setReturning(false),
            },
        );
    }

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'الفواتير', href: '/invoices' },
        { title: invoice.invoiceNumber, href: `/invoices/${invoice.type}/${invoice.id}` },
    ];

    const printBase = `/invoices/${invoice.type}/${invoice.id}/print`;
    const hasPayments = (invoice.payments?.length ?? 0) > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${invoiceDocumentTitle(invoice)} ${invoice.invoiceNumber}`} />
            <Toaster position="top-center" richColors />

            <div className="p-4 md:p-6">
                <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-1">
                        <div className="flex flex-wrap items-center gap-2 md:gap-3">
                            <h1 className="text-xl font-bold md:text-2xl" dir="ltr">
                                {invoice.invoiceNumber}
                            </h1>
                            <Badge variant="outline" className={INVOICE_STATUS_COLORS[invoice.status]}>
                                {invoice.statusLabel}
                            </Badge>
                            <Badge variant="secondary">{invoice.typeLabel}</Badge>
                            <Badge variant="outline">{invoiceDocumentTitle(invoice)}</Badge>
                            {invoice.isFullyRefunded && invoice.status !== 'returned' && (
                                <Badge variant="outline" className="border-amber-200 bg-amber-50 text-amber-700">
                                    مُرتجعة
                                </Badge>
                            )}
                        </div>
                        {invoice.createdAt && <p className="text-muted-foreground text-sm">{formatDateTime(invoice.createdAt)}</p>}
                    </div>
                    {/* Full-width pairs on a phone; a single inline row once there is room. */}
                    <div className="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:flex-wrap">
                        {invoice.canApprovePayment && (
                            <Button className="bg-emerald-600 text-white hover:bg-emerald-700" onClick={() => setApproveOpen(true)}>
                                <CheckCircle2 className="size-4" /> اعتماد الفاتورة
                            </Button>
                        )}
                        {invoice.canRecordPayment && (
                            <Button variant="outline" onClick={() => setPaymentOpen(true)}>
                                <Wallet className="size-4" /> تسجيل دفعة
                            </Button>
                        )}
                        {invoice.canRefund && (
                            <Button variant="outline" onClick={() => setRefundOpen(true)}>
                                <Undo2 className="size-4" /> إنشاء مرتجع
                            </Button>
                        )}
                        {invoice.canEdit && (
                            <Button variant="outline" asChild>
                                <a href={posService.edit(invoice.id).url}>
                                    <Pencil className="size-4" /> تعديل
                                </a>
                            </Button>
                        )}
                        {invoice.canReturn && (
                            <Button variant="outline" className="text-destructive hover:text-destructive" onClick={() => setReturnOpen(true)}>
                                <Undo2 className="size-4" /> استرجاع الفاتورة
                            </Button>
                        )}
                        {invoice.status !== 'cancelled' && (
                            <>
                                <Button variant="outline" asChild>
                                    <a href={`${printBase}?format=thermal`} target="_blank" rel="noreferrer">
                                        <ReceiptText className="size-4" /> إيصال حراري
                                    </a>
                                </Button>
                                <Button asChild>
                                    <a href={`${printBase}?format=a4`} target="_blank" rel="noreferrer">
                                        <Printer className="size-4" /> طباعة A4
                                    </a>
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                {/* Why the reviewer rejected the invoice — shown to everyone who may
                    view it, the employee who raised it above all (تاسك 18). */}
                {invoice.status === 'cancelled' && invoice.cancellationReason && (
                    <div
                        role="alert"
                        className="mb-6 flex items-start gap-3 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-red-800 dark:border-red-900 dark:bg-red-950/30 dark:text-red-300"
                    >
                        <Ban className="mt-0.5 size-4 shrink-0" />
                        <div className="space-y-1">
                            <p className="text-sm font-semibold">أُلغيت هذه الفاتورة</p>
                            <p className="text-sm whitespace-pre-line">{invoice.cancellationReason}</p>
                            {(invoice.cancelledByName || invoice.cancelledAt) && (
                                <p className="text-xs opacity-80">
                                    {invoice.cancelledByName && <>بواسطة {invoice.cancelledByName}</>}
                                    {invoice.cancelledByName && invoice.cancelledAt && ' — '}
                                    {invoice.cancelledAt && formatDateTime(invoice.cancelledAt)}
                                </p>
                            )}
                        </div>
                    </div>
                )}

                {/* الدفعات: العربون وما تلاه، وما بقي على العميل. تُعرض متى وُجدت
                    دفعة أو كانت الفاتورة ما تزال تقبل تحصيلاً. */}
                {(hasPayments || invoice.canRecordPayment) && (
                    <Card className="mb-6">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex flex-wrap items-center justify-between gap-2 text-base">
                                <span className="flex items-center gap-2">
                                    <Wallet className="size-4" /> الدفعات
                                </span>
                                <span className="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm font-normal">
                                    <span className="text-muted-foreground">
                                        المحصَّل:{' '}
                                        <span className="font-semibold text-green-700 tabular-nums dark:text-green-400" dir="ltr">
                                            {formatCurrency(invoice.paidAmount)}
                                        </span>
                                    </span>
                                    <span className="text-muted-foreground">
                                        المتبقي:{' '}
                                        <span
                                            className={`font-semibold tabular-nums ${invoice.paymentRemaining > 0 ? 'text-amber-700 dark:text-amber-400' : ''}`}
                                            dir="ltr"
                                        >
                                            {formatCurrency(invoice.paymentRemaining)}
                                        </span>
                                    </span>
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {hasPayments ? (
                                <div className="divide-y">
                                    {invoice.payments!.map((payment, i) => (
                                        <div key={payment.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                            <div className="flex flex-wrap items-center gap-3">
                                                <Badge variant="outline">{i === 0 ? 'عربون' : `دفعة ${i + 1}`}</Badge>
                                                <span className="text-muted-foreground tabular-nums" dir="ltr">
                                                    {payment.paidAt ? formatDateTime(payment.paidAt) : '—'}
                                                </span>
                                                {payment.paymentMethod && <span className="text-muted-foreground">{payment.paymentMethod}</span>}
                                                {payment.notes && <span className="text-muted-foreground text-xs">{payment.notes}</span>}
                                            </div>
                                            <div className="flex items-center gap-3">
                                                <span className="font-semibold tabular-nums" dir="ltr">
                                                    {formatCurrency(payment.amount)}
                                                </span>
                                                <span className="text-muted-foreground text-xs">{payment.recordedByName ?? '—'}</span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-muted-foreground py-2 text-sm">لم تُسجَّل أي دفعة على هذه الفاتورة بعد.</p>
                            )}
                            {invoice.canRecordPayment && (
                                <Button variant="outline" size="sm" className="mt-3" onClick={() => setPaymentOpen(true)}>
                                    <Wallet className="size-4" /> تسجيل دفعة
                                </Button>
                            )}
                        </CardContent>
                    </Card>
                )}

                {invoice.refunds && invoice.refunds.length > 0 && (
                    <Card className="mb-6 border-amber-200 bg-amber-50/40">
                        <CardHeader className="pb-3">
                            <CardTitle className="flex flex-wrap items-center justify-between gap-2 text-base">
                                <span className="flex items-center gap-2 text-amber-800">
                                    <Undo2 className="size-4" /> المرتجعات
                                </span>
                                <span className="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm font-normal">
                                    <span className="text-muted-foreground">
                                        إجمالي المرتجع:{' '}
                                        <span className="text-destructive font-semibold tabular-nums" dir="ltr">
                                            −{formatCurrency(invoice.refundedTotal)}
                                        </span>
                                    </span>
                                    <span className="text-muted-foreground">
                                        المتبقي القابل للإرجاع:{' '}
                                        <span className="font-semibold tabular-nums" dir="ltr">
                                            {formatCurrency(invoice.refundableRemaining)}
                                        </span>
                                    </span>
                                </span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="divide-y divide-amber-100">
                                {invoice.refunds.map((refund) => (
                                    <div key={refund.id} className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                                        <div className="flex flex-wrap items-center gap-3">
                                            <span className="text-muted-foreground tabular-nums" dir="ltr">
                                                {refund.createdAt ? formatDateTime(refund.createdAt) : '—'}
                                            </span>
                                            <span>{refund.reason}</span>
                                            {refund.stockReversed && (
                                                <Badge variant="outline" className="border-green-200 bg-green-50 text-green-700">
                                                    أُرجع المخزون
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-3">
                                            <span className="text-destructive font-semibold tabular-nums" dir="ltr">
                                                −{formatCurrency(refund.amount)}
                                            </span>
                                            <span className="text-muted-foreground">{refund.userName ?? '—'}</span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Line items */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>بنود الفاتورة</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <DataTable
                                className="rounded-none border-0 bg-transparent shadow-none"
                                columns={lineColumns}
                                data={invoice.lines}
                                keyExtractor={(line) => invoice.lines.indexOf(line)}
                            />

                            <InvoiceNotes notes={invoice.notes} />

                            <Separator className="my-4" />

                            <div className="ms-auto max-w-xs space-y-2">
                                <TotalRow label="المجموع الفرعي" value={formatCurrency(invoice.subtotal)} />
                                {invoice.tierDiscountAmount > 0 && (
                                    <TotalRow label="خصم المستوى" value={`−${formatCurrency(invoice.tierDiscountAmount)}`} />
                                )}
                                {invoice.couponDiscount > 0 && <TotalRow label="خصم الكوبون" value={`−${formatCurrency(invoice.couponDiscount)}`} />}
                                {invoice.agentDiscount > 0 && <TotalRow label="خصم المندوب" value={`−${formatCurrency(invoice.agentDiscount)}`} />}
                                {invoice.pointsDiscount > 0 && <TotalRow label="خصم النقاط" value={`−${formatCurrency(invoice.pointsDiscount)}`} />}
                                <TotalRow label={`الضريبة (${invoice.vatPct}%)`} value={formatCurrency(invoice.vatAmount)} />
                                <Separator className="my-1" />
                                <TotalRow label="الإجمالي" value={formatCurrency(invoice.totalAmount)} strong />
                                {invoice.agents
                                    .filter((a) => a.rebate > 0)
                                    .map((a, i) => (
                                        <TotalRow
                                            key={i}
                                            label={`عمولة المندوب المرتجعة${a.name ? ` (${a.name})` : ''}`}
                                            value={formatCurrency(a.rebate)}
                                        />
                                    ))}
                                {invoice.agents
                                    .filter((a) => a.lineCommission > 0)
                                    .map((a, i) => (
                                        <TotalRow
                                            key={`lc-${i}`}
                                            label={`عمولة البنود${a.name ? ` (${a.name})` : ''}`}
                                            value={formatCurrency(a.lineCommission)}
                                        />
                                    ))}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Meta */}
                    <Card>
                        <CardHeader>
                            <CardTitle>التفاصيل</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <MetaRow label="العميل" value={invoice.customerName ?? 'عميل نقدي'} />
                            {invoice.customerPhone && <MetaRow label="الهاتف" value={<span dir="ltr">{invoice.customerPhone}</span>} />}
                            {invoice.customerTaxNumber && (
                                <MetaRow label="الرقم الضريبي للعميل" value={<span dir="ltr">{invoice.customerTaxNumber}</span>} />
                            )}
                            <MetaRow label="طريقة الدفع" value={invoice.paymentMethod ?? '—'} />
                            {invoice.deliveryAt && (
                                <MetaRow
                                    label="موعد التسليم"
                                    value={<DeliveryBadge deliveryAt={invoice.deliveryAt} deliveryStatus={invoice.deliveryStatus} />}
                                />
                            )}
                            {invoice.receiptUrl && (
                                <MetaRow
                                    label="إيصال التحويل"
                                    value={
                                        <a
                                            href={invoice.receiptUrl}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="text-primary inline-flex items-center gap-1 hover:underline"
                                        >
                                            <Paperclip className="size-3.5" /> عرض الإيصال
                                        </a>
                                    }
                                />
                            )}
                            {invoice.paidAt && <MetaRow label="تاريخ الدفع" value={formatDateTime(invoice.paidAt)} />}
                            {invoice.employeeCommission !== null && (
                                <MetaRow label="عمولة الموظف" value={<span dir="ltr">{formatCurrency(invoice.employeeCommission)}</span>} />
                            )}
                            <Separator className="my-3" />
                            <MetaRow label="الفرع" value={invoice.branch.name ?? '—'} />
                            {invoice.branch.taxNumber && <MetaRow label="الرقم الضريبي" value={<span dir="ltr">{invoice.branch.taxNumber}</span>} />}
                        </CardContent>
                    </Card>
                </div>
            </div>

            {invoice.canRecordPayment && (
                <RecordPaymentModal
                    open={paymentOpen}
                    onOpenChange={setPaymentOpen}
                    invoiceType={invoice.type}
                    invoiceId={invoice.id}
                    invoiceNumber={invoice.invoiceNumber}
                    remaining={invoice.paymentRemaining}
                    paymentMethods={paymentMethodOptions}
                />
            )}

            {invoice.canRefund && (
                <RefundFormModal
                    key={refundOpen ? 'open' : 'closed'}
                    open={refundOpen}
                    onOpenChange={setRefundOpen}
                    presetNumber={invoice.invoiceNumber}
                />
            )}

            {/* Approve-payment confirmation */}
            <Dialog open={approveOpen} onOpenChange={(open) => !open && setApproveOpen(false)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>اعتماد دفع الفاتورة</DialogTitle>
                        <DialogDescription>
                            سيتم تحويل الفاتورة {invoice.invoiceNumber} إلى مدفوعة بمبلغ {formatCurrency(invoice.totalAmount)} واحتساب نقاط الولاء إن
                            وُجدت.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setApproveOpen(false)} disabled={approving}>
                            تراجع
                        </Button>
                        <Button onClick={confirmApprove} disabled={approving}>
                            <CheckCircle2 className="size-4" /> تأكيد الدفع
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Return confirmation */}
            <Dialog open={returnOpen} onOpenChange={(open) => !open && !returning && setReturnOpen(false)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>استرجاع الفاتورة</DialogTitle>
                        <DialogDescription>
                            تبقى الفاتورة {invoice.invoiceNumber} ظاهرة في القوائم بحالة «مرتجع» ولا تُحذف
                            {invoice.status === 'paid'
                                ? '، ويُسجَّل لها مرتجع بكامل المتبقي مع عكس العمولة غير المدفوعة وسحب نقاط الولاء المكتسبة واسترجاع أي نقاط مستبدلة.'
                                : '، مع عكس العمولة غير المدفوعة واسترجاع أي نقاط مستبدلة.'}{' '}
                            لا يمكن التراجع عن هذا الإجراء.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-1">
                        <label htmlFor="invoice-return-reason" className="text-sm font-medium">
                            سبب الاسترجاع <span className="text-muted-foreground font-normal">(اختياري)</span>
                        </label>
                        <textarea
                            id="invoice-return-reason"
                            rows={3}
                            value={returnReason}
                            onChange={(e) => setReturnReason(e.target.value)}
                            placeholder="سبب استرجاع الفاتورة..."
                            disabled={returning}
                            className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[80px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                        />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setReturnOpen(false)} disabled={returning}>
                            تراجع
                        </Button>
                        <Button variant="destructive" onClick={confirmReturn} disabled={returning}>
                            <Undo2 className="size-4" /> تأكيد الاسترجاع
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
