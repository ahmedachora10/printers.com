import RefundTabs from '@/components/refunds/refund-tabs';
import ReturnRequestDialogs from '@/components/refunds/return-request-dialogs';
import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
                            <Button size="sm" className="bg-emerald-600 text-white hover:bg-emerald-700" onClick={() => setApproving(item)}>
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

            <ReturnRequestDialogs
                approving={approving}
                rejecting={rejecting}
                paymentMethods={approving ? (paymentMethods[approving.branchId] ?? []) : []}
                onClose={() => {
                    setApproving(null);
                    setRejecting(null);
                }}
            />
        </AppLayout>
    );
}
