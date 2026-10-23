import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import posProduct from '@/routes/pos/product';
import posService from '@/routes/pos/service';
import quotations from '@/routes/quotations';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type PaginatedQuotations, type QuotationListItem, type QuotationStatus } from '@/types/quotation';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, ClipboardList, FileOutput, Pencil, Plus, Printer, Search, Send, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'عروض الأسعار', href: quotations.index().url }];

const STATUS_COLORS: Record<QuotationStatus, string> = {
    new: 'border-sky-200 bg-sky-50 text-sky-700',
    pending: 'border-amber-200 bg-amber-50 text-amber-700',
    approved: 'border-green-200 bg-green-50 text-green-700',
    rejected: 'border-red-200 bg-red-50 text-red-700',
    converted: 'border-border bg-muted/60 text-muted-foreground',
};

/** أسباب الرفض الشائعة كما ذكرها العميل — والسبب كله اختياري. */
const REJECTION_REASONS = ['ارتفاع السعر', 'جودة العمل', 'أخرى'];

interface Props {
    quotations: PaginatedQuotations;
    filters: { status: QuotationStatus | null; search: string };
    statusOptions: { value: QuotationStatus; label: string }[];
    canCreate: { service: boolean; product: boolean };
}

const posFor = (q: Pick<QuotationListItem, 'type'>) => (q.type === 'service' ? posService : posProduct);

export default function QuotationsIndex({ quotations: items, filters, statusOptions, canCreate }: Props) {
    const { props } = usePage<SharedData>();
    const [search, setSearch] = useState(filters.search);
    const searchTimeout = useRef<ReturnType<typeof setTimeout> | null>(null);
    const [rejecting, setRejecting] = useState<QuotationListItem | null>(null);
    const [reasonKind, setReasonKind] = useState('');
    const [reasonText, setReasonText] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (props.success) toast.success(props.success as string);
    }, [props.success]);

    const filter = (next: Partial<Props['filters']>) =>
        router.get(quotations.index().url, { ...filters, ...next }, { preserveState: true, replace: true });

    const handleSearch = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => filter({ search: value }), 400);
    };

    const post = (url: string, data: Record<string, string | null> = {}, onSuccess?: () => void) => {
        router.post(url, data, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onError: (e) => toast.error(Object.values(e)[0] ?? 'تعذّر تحديث عرض السعر.'),
            onSuccess,
            onFinish: () => setBusy(false),
        });
    };

    function confirmReject() {
        if (!rejecting) return;
        const reason = reasonKind === 'أخرى' ? reasonText.trim() || 'أخرى' : [reasonKind, reasonText.trim()].filter(Boolean).join(' — ');
        post(quotations.reject(rejecting.id).url, { reason: reason || null }, () => {
            setRejecting(null);
            setReasonKind('');
            setReasonText('');
        });
    }

    const columns = useMemo<ColumnDef<QuotationListItem>[]>(
        () => [
            {
                key: 'number',
                header: 'رقم العرض',
                mobilePrimary: true,
                cell: (q) => (
                    <div>
                        <span className="font-medium" dir="ltr">
                            {q.number}
                        </span>
                        <span className="text-muted-foreground block text-xs">{q.type === 'service' ? 'خدمات' : 'منتجات'}</span>
                    </div>
                ),
            },
            {
                key: 'customer',
                header: 'العميل',
                cell: (q) => (
                    <div className="text-sm">
                        {q.customerName ?? 'عميل عابر'}
                        {q.customerPhone && (
                            <span className="text-muted-foreground block text-xs" dir="ltr">
                                {q.customerPhone}
                            </span>
                        )}
                    </div>
                ),
            },
            {
                key: 'employee',
                header: 'الموظف',
                cell: (q) => (
                    <span className="text-sm">
                        {q.employeeName ?? '—'}
                        {q.branchName && <span className="text-muted-foreground block text-xs">{q.branchName}</span>}
                    </span>
                ),
            },
            {
                key: 'totalAmount',
                header: 'الإجمالي',
                cell: (q) => (
                    <span className="font-semibold tabular-nums" dir="ltr">
                        {formatCurrency(q.totalAmount)}
                    </span>
                ),
            },
            {
                key: 'status',
                header: 'الحالة',
                cell: (q) => (
                    <div className="space-y-1">
                        <Badge variant="outline" className={STATUS_COLORS[q.status]}>
                            {q.statusLabel}
                        </Badge>
                        {q.rejectionReason && <p className="text-muted-foreground max-w-xs text-xs">{q.rejectionReason}</p>}
                        {q.invoiceId && (
                            <Link
                                href={`/invoices/${q.type}/${q.invoiceId}`}
                                className="block text-xs font-medium text-sky-700 hover:underline"
                                dir="ltr"
                            >
                                {q.invoiceNumber}
                            </Link>
                        )}
                    </div>
                ),
            },
            {
                key: 'createdAt',
                header: 'التاريخ',
                cell: (q) => <span className="text-muted-foreground text-sm">{q.createdAt ? formatDateTime(q.createdAt) : '—'}</span>,
            },
            {
                key: 'actions',
                header: '',
                cell: (q) => {
                    const canSend = q.can.changeStatus && q.status === 'new';
                    const canAccept = q.can.changeStatus && (q.status === 'new' || q.status === 'pending');
                    const canReject = q.can.changeStatus && ['new', 'pending', 'approved'].includes(q.status);

                    return (
                        <div className="flex flex-wrap gap-1.5">
                            {q.can.convert && (
                                <Button size="sm" className="bg-emerald-600 text-white hover:bg-emerald-700" asChild>
                                    <Link href={posFor(q).quotation(q.id, { query: { convert: 1 } }).url}>
                                        <FileOutput className="size-4" /> تحويل إلى فاتورة
                                    </Link>
                                </Button>
                            )}
                            {q.can.print && (
                                <Button size="sm" variant="outline" asChild>
                                    <Link href={posFor(q).quotation.print(q.id).url}>
                                        <Printer className="size-4" /> طباعة
                                    </Link>
                                </Button>
                            )}
                            {q.can.update && (
                                <Button size="sm" variant="outline" asChild>
                                    <Link href={posFor(q).quotation(q.id).url}>
                                        <Pencil className="size-4" /> تعديل
                                    </Link>
                                </Button>
                            )}
                            {canSend && (
                                <Button size="sm" variant="outline" disabled={busy} onClick={() => post(quotations.send(q.id).url)}>
                                    <Send className="size-4" /> أُرسل للعميل
                                </Button>
                            )}
                            {canAccept && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="text-emerald-700"
                                    disabled={busy}
                                    onClick={() => post(quotations.accept(q.id).url)}
                                >
                                    <Check className="size-4" /> وافق العميل
                                </Button>
                            )}
                            {canReject && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="text-destructive hover:text-destructive"
                                    disabled={busy}
                                    onClick={() => setRejecting(q)}
                                >
                                    <X className="size-4" /> رفض
                                </Button>
                            )}
                        </div>
                    );
                },
            },
        ],
        [busy],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="عروض الأسعار" />
            <Toaster position="top-center" richColors />

            <div className="space-y-4 p-4 sm:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h1 className="flex items-center gap-2 text-2xl font-bold">
                        <ClipboardList className="size-6" /> عروض الأسعار
                    </h1>
                    <div className="flex flex-wrap gap-2">
                        {canCreate.service && (
                            <Button asChild>
                                <Link href={posService.quotation().url}>
                                    <Plus className="size-4" /> عرض سعر خدمات
                                </Link>
                            </Button>
                        )}
                        {canCreate.product && (
                            <Button variant={canCreate.service ? 'outline' : 'default'} asChild>
                                <Link href={posProduct.quotation().url}>
                                    <Plus className="size-4" /> عرض سعر منتجات
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                <p className="text-muted-foreground text-sm">عروض الأسعار لا تُحتسب في المبيعات ولا العمولات ولا الإيرادات حتى تُحوَّل إلى فاتورة.</p>

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative min-w-0 flex-1 sm:max-w-72">
                        <Search className="text-muted-foreground pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2" />
                        <Input value={search} onChange={(e) => handleSearch(e.target.value)} placeholder="رقم العرض أو العميل..." className="ps-8" />
                    </div>
                    <Select
                        value={filters.status ?? 'all'}
                        onValueChange={(status) => filter({ status: status === 'all' ? null : (status as QuotationStatus) })}
                    >
                        <SelectTrigger className="h-9 w-full sm:w-52" aria-label="الحالة">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">كل الحالات</SelectItem>
                            {statusOptions.map((s) => (
                                <SelectItem key={s.value} value={s.value}>
                                    {s.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <DataTable
                    rowOffset={Number(items.meta.from ?? 1) - 1}
                    columns={columns}
                    data={items.data}
                    keyExtractor={(q) => q.id}
                    emptyState={<p className="text-muted-foreground py-8 text-center text-sm">لا توجد عروض أسعار.</p>}
                />

                <TablePagination
                    currentPage={items.meta.current_page}
                    totalPages={items.meta.last_page}
                    totalItems={items.meta.total}
                    from={items.meta.from}
                    to={items.meta.to}
                    onPageChange={(page) => router.reload({ data: { page } })}
                />
            </div>

            <Dialog open={!!rejecting} onOpenChange={(open) => !open && setRejecting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>رفض العميل لعرض السعر {rejecting?.number}</DialogTitle>
                        <DialogDescription>السبب اختياري — اذكره إن عرفته من العميل.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-3">
                        <div className="flex flex-wrap gap-2">
                            {REJECTION_REASONS.map((r) => (
                                <Button
                                    key={r}
                                    type="button"
                                    size="sm"
                                    variant={reasonKind === r ? 'default' : 'outline'}
                                    onClick={() => setReasonKind(reasonKind === r ? '' : r)}
                                >
                                    {r}
                                </Button>
                            ))}
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="quotation-reject-reason">تفاصيل (اختياري)</Label>
                            <textarea
                                id="quotation-reject-reason"
                                rows={3}
                                maxLength={1000}
                                value={reasonText}
                                onChange={(e) => setReasonText(e.target.value)}
                                className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[80px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                            />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRejecting(null)}>
                            تراجع
                        </Button>
                        <Button variant="destructive" disabled={busy} onClick={confirmReject}>
                            <X className="size-4" /> تسجيل الرفض
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
