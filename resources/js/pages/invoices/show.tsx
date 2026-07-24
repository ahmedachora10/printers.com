import { DataTable, type ColumnDef } from '@/components/data-table';
import RefundFormModal from '@/components/refunds/refund-form-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import serviceInvoice from '@/routes/invoices/service';
import posService from '@/routes/pos/service';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type Invoice } from '@/types/invoice';
import { Head, router, usePage } from '@inertiajs/react';
import { CheckCircle2, Paperclip, Pencil, Printer, ReceiptText, Trash2, Undo2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

const STATUS_COLORS: Record<string, string> = {
    paid: 'border-green-200 bg-green-50 text-green-700',
    due: 'border-red-200 bg-red-50 text-red-700',
    cancelled: 'border-border bg-muted/60 text-muted-foreground',
};

interface Props {
    invoice: Invoice;
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

export default function InvoiceShow({ invoice }: Props) {
    const { props } = usePage<SharedData>();
    const [refundOpen, setRefundOpen] = useState(false);
    const [approveOpen, setApproveOpen] = useState(false);
    const [approving, setApproving] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleting, setDeleting] = useState(false);

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

    function confirmDelete() {
        setDeleting(true);
        router.delete(posService.destroy(invoice.id).url, {
            onError: (e) => {
                toast.error((Object.values(e)[0] as string) ?? 'تعذّر حذف الفاتورة.');
                setDeleting(false);
                setDeleteOpen(false);
            },
            onFinish: () => setDeleting(false),
        });
    }

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'الفواتير', href: '/invoices' },
        { title: invoice.invoiceNumber, href: `/invoices/${invoice.type}/${invoice.id}` },
    ];

    const printBase = `/invoices/${invoice.type}/${invoice.id}/print`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`فاتورة ${invoice.invoiceNumber}`} />
            <Toaster position="top-center" richColors />

            <div className="p-6">
                <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex items-center gap-3">
                            <h1 className="text-2xl font-bold" dir="ltr">
                                {invoice.invoiceNumber}
                            </h1>
                            <Badge variant="outline" className={STATUS_COLORS[invoice.status]}>
                                {invoice.statusLabel}
                            </Badge>
                            <Badge variant="secondary">{invoice.typeLabel}</Badge>
                            <Badge variant="outline">{invoice.customerTaxNumber ? 'فاتورة ضريبية' : 'فاتورة ضريبية مبسطة'}</Badge>
                            {invoice.isFullyRefunded && (
                                <Badge variant="outline" className="border-amber-200 bg-amber-50 text-amber-700">
                                    مُرتجعة
                                </Badge>
                            )}
                        </div>
                        {invoice.createdAt && <p className="text-muted-foreground text-sm">{formatDateTime(invoice.createdAt)}</p>}
                    </div>
                    <div className="flex gap-2">
                        {invoice.canApprovePayment && (
                            <Button className="bg-emerald-600 text-white hover:bg-emerald-700" onClick={() => setApproveOpen(true)}>
                                <CheckCircle2 className="size-4" /> اعتماد الفاتورة
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
                        {invoice.canDelete && (
                            <Button variant="outline" className="text-destructive hover:text-destructive" onClick={() => setDeleteOpen(true)}>
                                <Trash2 className="size-4" /> حذف
                            </Button>
                        )}
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
                    </div>
                </div>

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

                            <Separator className="my-4" />

                            <div className="ms-auto max-w-xs space-y-2">
                                <TotalRow label="المجموع الفرعي" value={formatCurrency(invoice.subtotal)} />
                                {invoice.tierDiscountAmount > 0 && (
                                    <TotalRow label="خصم المستوى" value={`−${formatCurrency(invoice.tierDiscountAmount)}`} />
                                )}
                                {invoice.couponDiscount > 0 && <TotalRow label="خصم الكوبون" value={`−${formatCurrency(invoice.couponDiscount)}`} />}
                                {invoice.agentDiscount > 0 && <TotalRow label="خصم الوكيل" value={`−${formatCurrency(invoice.agentDiscount)}`} />}
                                {invoice.pointsDiscount > 0 && <TotalRow label="خصم النقاط" value={`−${formatCurrency(invoice.pointsDiscount)}`} />}
                                <TotalRow label={`الضريبة (${invoice.vatPct}%)`} value={formatCurrency(invoice.vatAmount)} />
                                <Separator className="my-1" />
                                <TotalRow label="الإجمالي" value={formatCurrency(invoice.totalAmount)} strong />
                                {invoice.agents
                                    .filter((a) => a.rebate > 0)
                                    .map((a, i) => (
                                        <TotalRow
                                            key={i}
                                            label={`عمولة الوكيل المرتجعة${a.name ? ` (${a.name})` : ''}`}
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

            {/* Delete confirmation */}
            <Dialog open={deleteOpen} onOpenChange={(open) => !open && setDeleteOpen(false)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>حذف الفاتورة</DialogTitle>
                        <DialogDescription>
                            سيتم حذف الفاتورة {invoice.invoiceNumber} نهائياً من القوائم
                            {invoice.status === 'paid'
                                ? ' مع عكس العمولة غير المدفوعة وسحب نقاط الولاء المكتسبة واسترجاع أي نقاط مستبدلة.'
                                : ' مع عكس العمولة غير المدفوعة واسترجاع أي نقاط مستبدلة.'}{' '}
                            لا يمكن التراجع عن هذا الإجراء.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeleteOpen(false)} disabled={deleting}>
                            تراجع
                        </Button>
                        <Button variant="destructive" onClick={confirmDelete} disabled={deleting}>
                            <Trash2 className="size-4" /> تأكيد الحذف
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
