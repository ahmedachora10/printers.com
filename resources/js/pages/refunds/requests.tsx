import RefundTabs from '@/components/refunds/refund-tabs';
import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import refunds from '@/routes/refunds';
import { type BreadcrumbItem } from '@/types';
import { type PaginatedReturnRequest, type ReturnRequestItem, type ReturnRequestStatus } from '@/types/return-request';
import { Link, router } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'المرتجعات', href: '/refunds' },
    { title: 'طلبات الاسترجاع', href: '/refunds/requests' },
];

const STATUS_COLORS: Record<ReturnRequestStatus, string> = {
    pending: 'border-amber-200 bg-amber-50 text-amber-700',
    completed: 'border-green-200 bg-green-50 text-green-700',
    rejected: 'border-red-200 bg-red-50 text-red-700',
};

interface Props {
    items: PaginatedReturnRequest;
    /** طرق الردّ لكل فرعٍ في الصفحة، بمفتاح رقم الفرع */
    paymentMethods: Record<number, { id: number; name: string }[]>;
    statuses: { value: ReturnRequestStatus; label: string }[];
    filters: { status: string };
}

export default function ReturnRequestsIndex({ items, paymentMethods, statuses, filters }: Props) {
    const [approving, setApproving] = useState<ReturnRequestItem | null>(null);
    const [rejecting, setRejecting] = useState<ReturnRequestItem | null>(null);
    const [methodId, setMethodId] = useState('');
    const [amount, setAmount] = useState('');
    const [rejectionReason, setRejectionReason] = useState('');
    const [processing, setProcessing] = useState(false);

    function close() {
        setApproving(null);
        setRejecting(null);
        setMethodId('');
        setAmount('');
        setRejectionReason('');
    }

    function startApproving(item: ReturnRequestItem) {
        setApproving(item);
        setAmount(item.amount > 0 ? String(item.amount) : '');
    }

    function submit(url: string, data: Record<string, string | null>, success: string) {
        setProcessing(true);
        router.post(url, data, {
            preserveScroll: true,
            onSuccess: () => {
                close();
                toast.success(success);
            },
            onError: (e) => toast.error((Object.values(e)[0] as string) ?? 'تعذّر تنفيذ الطلب.'),
            onFinish: () => setProcessing(false),
        });
    }

    const columns = useMemo<ColumnDef<ReturnRequestItem>[]>(
        () => [
            {
                key: 'invoiceNumber',
                header: 'رقم الفاتورة',
                cell: (item) => (
                    <Link href={`/invoices/service/${item.invoiceId}`} className="font-medium hover:underline" dir="ltr">
                        {item.invoiceNumber}
                    </Link>
                ),
            },
            {
                key: 'amount',
                header: 'المبلغ المُردّ',
                cell: (item) => (
                    <span className="font-semibold tabular-nums text-destructive" dir="ltr">
                        {formatCurrency(item.amount)}
                    </span>
                ),
            },
            { key: 'requesterName', header: 'الموظف', cell: (item) => <span className="text-sm">{item.requesterName ?? '—'}</span> },
            {
                key: 'reason',
                header: 'السبب',
                cell: (item) => (
                    <span className="block max-w-xs truncate text-sm text-muted-foreground" title={item.reason ?? ''}>
                        {item.reason ?? '—'}
                    </span>
                ),
            },
            {
                key: 'status',
                header: 'الحالة',
                cell: (item) => (
                    <div className="space-y-1">
                        <Badge variant="outline" className={STATUS_COLORS[item.status]}>
                            {item.statusLabel}
                        </Badge>
                        {item.rejectionReason && <p className="max-w-xs text-xs text-muted-foreground">{item.rejectionReason}</p>}
                    </div>
                ),
            },
            { key: 'paymentMethodName', header: 'طريقة الردّ', cell: (item) => <span className="text-sm">{item.paymentMethodName ?? '—'}</span> },
            {
                key: 'deciderName',
                header: 'بواسطة',
                cell: (item) => (
                    <span className="text-sm">
                        {item.deciderName ?? '—'}
                        {item.decidedAt && <span className="block text-xs text-muted-foreground">{formatDateTime(item.decidedAt)}</span>}
                    </span>
                ),
            },
            {
                key: 'createdAt',
                header: 'تاريخ الطلب',
                cell: (item) => <span className="text-sm text-muted-foreground">{item.createdAt ? formatDateTime(item.createdAt) : '—'}</span>,
            },
            {
                key: 'actions',
                header: '',
                cell: (item) =>
                    item.canDecide && (
                        <div className="flex gap-2">
                            <Button size="sm" className="bg-emerald-600 text-white hover:bg-emerald-700" onClick={() => startApproving(item)}>
                                <Check className="size-4" /> اعتماد
                            </Button>
                            <Button size="sm" variant="outline" className="text-destructive hover:text-destructive" onClick={() => setRejecting(item)}>
                                <X className="size-4" /> رفض
                            </Button>
                        </div>
                    ),
            },
        ],
        [],
    );

    const methods = approving ? (paymentMethods[approving.branchId] ?? []) : [];
    const refundable = approving?.amount ?? 0;
    const needsMethod = refundable > 0;
    const amountValue = Number(amount);
    const amountValid = !needsMethod || (amountValue > 0 && amountValue <= refundable);
    const isPartial = needsMethod && amountValid && amountValue < refundable;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Toaster position="top-center" richColors />
            <div className="p-6">
                <h1 className="mb-4 text-2xl font-bold">المرتجعات</h1>
                <RefundTabs active="requests" />

                <div className="mb-4 flex justify-end">
                    <Select
                        value={filters.status}
                        onValueChange={(status) => router.get(refunds.requests.index().url, { status }, { preserveState: true, replace: true })}
                    >
                        <SelectTrigger className="h-9 w-full sm:w-48" aria-label="الحالة">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">كل الحالات</SelectItem>
                            {statuses.map((s) => (
                                <SelectItem key={s.value} value={s.value}>
                                    {s.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <DataTable rowOffset={Number(items.meta.from ?? 1) - 1} columns={columns} data={items.data} keyExtractor={(item) => item.id} />

                <TablePagination
                    currentPage={items.meta.current_page as number}
                    totalPages={items.meta.last_page as number}
                    totalItems={items.meta.total as number}
                    from={items.meta.from as number}
                    to={items.meta.to as number}
                    onPageChange={(page) => router.reload({ data: { page } })}
                />
            </div>

            <Dialog open={!!approving} onOpenChange={(open) => !open && !processing && close()}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>اعتماد طلب الاسترجاع</DialogTitle>
                        <DialogDescription>
                            {isPartial
                                ? `استرجاع جزئي: يُسجَّل مرتجع بمبلغ ${formatCurrency(amountValue)} وتبقى الفاتورة ${approving?.invoiceNumber} قائمة، وتُعكس العمولة غير المدفوعة ونقاط الولاء بنسبة المبلغ. يُغلق الطلب بعد الاعتماد.`
                                : `تُسترجع الفاتورة ${approving?.invoiceNumber} فوراً: تصير «مرتجع»، ويُسجَّل مرتجع بمبلغ ${formatCurrency(refundable)}، وتُعكس العمولة غير المدفوعة ونقاط الولاء.`}{' '}
                            لا يمكن التراجع عن هذا الإجراء.
                        </DialogDescription>
                    </DialogHeader>
                    {needsMethod && (
                        <div className="space-y-1">
                            <label htmlFor="approve-amount" className="text-sm font-medium">
                                مبلغ الردّ (الحدّ الأقصى {formatCurrency(refundable)})
                            </label>
                            <Input
                                id="approve-amount"
                                type="number"
                                inputMode="decimal"
                                min="0.01"
                                max={refundable}
                                step="0.01"
                                dir="ltr"
                                value={amount}
                                onChange={(e) => setAmount(e.target.value)}
                                disabled={processing}
                                aria-invalid={!amountValid}
                            />
                            {!amountValid && <p className="text-xs text-destructive">أدخل مبلغاً أكبر من صفر ولا يتجاوز ما حُصِّل.</p>}
                        </div>
                    )}
                    {needsMethod && (
                        <div className="space-y-1">
                            <label htmlFor="approve-method" className="text-sm font-medium">
                                طريقة ردّ المبلغ للعميل
                            </label>
                            <Select value={methodId} onValueChange={setMethodId} disabled={processing}>
                                <SelectTrigger id="approve-method">
                                    <SelectValue placeholder="نقداً أو تحويل بنكي…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {methods.map((m) => (
                                        <SelectItem key={m.id} value={String(m.id)}>
                                            {m.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                    <DialogFooter>
                        <Button variant="outline" onClick={close} disabled={processing}>
                            تراجع
                        </Button>
                        <Button
                            className="bg-emerald-600 text-white hover:bg-emerald-700"
                            disabled={processing || (needsMethod && !methodId) || !amountValid}
                            onClick={() =>
                                approving &&
                                submit(
                                    refunds.requests.approve(approving.id).url,
                                    { payment_method_id: methodId || null, amount: isPartial ? amount : null },
                                    isPartial ? 'تم اعتماد الطلب وتسجيل مرتجع جزئي.' : 'تم اعتماد الطلب واسترجاع الفاتورة.',
                                )
                            }
                        >
                            <Check className="size-4" /> {isPartial ? 'اعتماد مرتجع جزئي' : 'اعتماد واسترجاع'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={!!rejecting} onOpenChange={(open) => !open && !processing && close()}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>رفض طلب الاسترجاع</DialogTitle>
                        <DialogDescription>لا تتغيّر الفاتورة {rejecting?.invoiceNumber}، ويصل سبب الرفض للموظف.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-1">
                        <label htmlFor="rejection-reason" className="text-sm font-medium">
                            سبب الرفض
                        </label>
                        <textarea
                            id="rejection-reason"
                            rows={3}
                            value={rejectionReason}
                            onChange={(e) => setRejectionReason(e.target.value)}
                            disabled={processing}
                            className="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[80px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none disabled:opacity-50"
                        />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={close} disabled={processing}>
                            تراجع
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={processing || !rejectionReason.trim()}
                            onClick={() =>
                                rejecting &&
                                submit(refunds.requests.reject(rejecting.id).url, { rejection_reason: rejectionReason.trim() }, 'تم رفض طلب الاسترجاع.')
                            }
                        >
                            <X className="size-4" /> رفض الطلب
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
