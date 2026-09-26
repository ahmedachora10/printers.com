import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import DateRangeBar from '@/components/reports/date-range-bar';
import { FilterSelect } from '@/components/reports/filter-fields';
import { FilterModal } from '@/components/reports/filter-modal';
import { SummaryCard } from '@/components/reports/summary-card';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import {
    type DeliveryLogFilters,
    type DeliveryLogProviderRow,
    type DeliveryLogRow,
    type DeliveryLogTotals,
} from '@/types/delivery-log';
import { Head, router, useForm } from '@inertiajs/react';
import { Bike, Coins, HandCoins, Users, X } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'كشف التوصيل', href: '/shipping/deliveries' }];

const REPORT_URL = '/shipping/deliveries';

const dash = <span className="text-muted-foreground">—</span>;

interface Props {
    deliveries: { data: DeliveryLogRow[]; meta: Record<string, number | null> };
    byProvider: DeliveryLogProviderRow[];
    totals: DeliveryLogTotals;
    providers: { id: number; name: string }[];
    /** تاسك 111 — فئة مصروف التسوية؛ branchId null = عامة */
    expenseCategories: { id: number; name: string; branchId: number | null }[];
    branches: { id: number; name: string }[];
    isSuperAdmin: boolean;
    /** تاسك 127: المحاسب يقرأ الكشف ولا يسوّي أجر السائق. */
    canSettle: boolean;
    defaultDate: string;
    filters: DeliveryLogFilters;
}

const SOURCES = [
    { value: 'cash_drawer', label: 'نقداً لدى المحاسب' },
    { value: 'company_transfer', label: 'تحويل من حساب الشركة' },
];

/**
 * تاسك 93 — ما على كل سائق اليوم: كم رحلة، وإلى أين، وبكم.
 *
 * تاسك 111: «استلام مبلغ التوصيل» لكل طلب — مصروفٌ مربوطٌ بالطلب. لا أرصدة
 * للسائقين ولا دفعات مجمَّعة.
 */
export default function DeliveriesIndex({
    deliveries,
    byProvider,
    totals,
    providers,
    expenseCategories,
    branches,
    isSuperAdmin,
    canSettle,
    defaultDate,
    filters,
}: Props) {
    const canPickBranch = isSuperAdmin && branches.length > 0;
    const [settling, setSettling] = useState<DeliveryLogRow | null>(null);
    const [cancelling, setCancelling] = useState<DeliveryLogRow | null>(null);

    const defaults = useMemo<FilterValues>(
        () => ({ from: defaultDate, to: defaultDate, branch: 'all', provider: 'all' }),
        [defaultDate],
    );

    const applied: FilterValues = {
        from: filters.from ?? defaultDate,
        to: filters.to ?? defaultDate,
        branch: filters.branch ?? 'all',
        provider: filters.provider ?? 'all',
    };
    const f = useReportFilters(REPORT_URL, applied, defaults);

    const chips: FilterChip[] = [];
    if (f.isActive('branch')) {
        const name = branches.find((b) => b.id.toString() === applied.branch)?.name ?? applied.branch;
        chips.push({ key: 'branch', label: `الفرع: ${name}`, onRemove: () => f.remove('branch') });
    }
    if (f.isActive('provider')) {
        const name = providers.find((p) => p.id.toString() === applied.provider)?.name ?? applied.provider;
        chips.push({ key: 'provider', label: `السائق: ${name}`, onRemove: () => f.remove('provider') });
    }

    const providerColumns = useMemo<ColumnDef<DeliveryLogProviderRow>[]>(
        () => [
            {
                key: 'providerName',
                header: 'السائق / الشركة',
                className: 'font-medium',
                cell: (row) => row.providerName ?? dash,
            },
            {
                key: 'providerPhone',
                header: 'الجوال',
                cell: (row) =>
                    row.providerPhone ? (
                        <span dir="ltr" className="inline-block text-start">
                            {row.providerPhone}
                        </span>
                    ) : (
                        dash
                    ),
            },
            { key: 'deliveries', header: 'عدد التوصيلات', cell: (row) => row.deliveries },
            {
                key: 'fees',
                header: 'جملة قيم التوصيل',
                className: 'font-semibold',
                cell: (row) => formatCurrency(row.fees),
            },
        ],
        [],
    );

    const detailColumns = useMemo<ColumnDef<DeliveryLogRow>[]>(
        () => [
            { key: 'createdAt', header: 'التاريخ', cell: (row) => (row.createdAt ? formatDateTime(row.createdAt) : dash) },
            {
                key: 'invoiceNumber',
                header: 'رقم الطلب',
                className: 'font-medium',
                cell: (row) => (
                    <span dir="ltr" className="inline-block text-start">
                        {row.invoiceNumber}
                    </span>
                ),
            },
            { key: 'customerName', header: 'العميل', cell: (row) => row.customerName ?? dash },
            {
                key: 'customerPhone',
                header: 'الجوال',
                cell: (row) =>
                    row.customerPhone ? (
                        <span dir="ltr" className="inline-block text-start">
                            {row.customerPhone}
                        </span>
                    ) : (
                        dash
                    ),
            },
            {
                key: 'address',
                header: 'العنوان',
                cell: (row) => (row.address ? <span className="block max-w-64 truncate">{row.address}</span> : dash),
            },
            { key: 'zoneName', header: 'المنطقة', cell: (row) => row.zoneName ?? dash },
            { key: 'providerName', header: 'السائق', cell: (row) => row.providerName ?? dash },
            ...(isSuperAdmin
                ? [{ key: 'branchName', header: 'الفرع', cell: (row: DeliveryLogRow) => row.branchName ?? dash } as ColumnDef<DeliveryLogRow>]
                : []),
            { key: 'statusLabel', header: 'الحالة', cell: (row) => row.statusLabel },
            {
                key: 'shippingFee',
                header: 'قيمة التوصيل',
                className: 'font-semibold',
                cell: (row) => (row.shippingFee > 0 ? formatCurrency(row.shippingFee) : 'مجاني'),
            },
            {
                key: 'settlement',
                header: 'تسوية السائق',
                cell: (row) =>
                    row.settlement ? (
                        <div className="flex items-start gap-1">
                            <div className="text-xs">
                                <Badge variant="secondary" className="text-green-700">
                                    مسوّاة — {formatCurrency(row.settlement.amount)}
                                </Badge>
                                <p className="text-muted-foreground mt-1">
                                    {row.settlement.paidFromLabel} — {row.settlement.settledByName ?? '—'}
                                    {row.settlement.settledAt && <> — {formatDateTime(row.settlement.settledAt)}</>}
                                </p>
                            </div>
                            {canSettle && (
                                <Button variant="ghost" size="icon" className="size-7" title="إلغاء التسوية" onClick={() => setCancelling(row)}>
                                    <X className="size-3.5" />
                                </Button>
                            )}
                        </div>
                    ) : canSettle ? (
                        <Button size="sm" variant="outline" className="whitespace-nowrap" onClick={() => setSettling(row)}>
                            <HandCoins className="size-4" /> استلام مبلغ التوصيل
                        </Button>
                    ) : (
                        dash
                    ),
            },
        ],
        [isSuperAdmin, canSettle],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="كشف التوصيل" />
            <div className="p-4 md:p-6">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-bold md:text-2xl">كشف التوصيل</h1>
                        <p className="text-muted-foreground text-sm">توصيلات المدى المحدد، مجموعةً بالسائق. يفتح على اليوم.</p>
                    </div>
                    <FilterModal open={f.open} onOpenChange={f.onOpenChange} onApply={f.apply} onReset={f.reset} activeCount={f.activeCount}>
                        {canPickBranch && (
                            <FilterSelect
                                label="الفرع"
                                value={f.draft.branch}
                                onChange={(v) => f.setField('branch', v)}
                                allLabel="كل الفروع"
                                options={branches.map((b) => ({ value: b.id.toString(), label: b.name }))}
                            />
                        )}
                        <FilterSelect
                            label="السائق"
                            value={f.draft.provider}
                            onChange={(v) => f.setField('provider', v)}
                            allLabel="كل السائقين"
                            options={providers.map((p) => ({ value: p.id.toString(), label: p.name }))}
                        />
                    </FilterModal>
                </div>

                <div className="mb-6">
                    <DateRangeBar filters={f} from={applied.from} to={applied.to} />
                </div>

                <ActiveFilterChips chips={chips} />

                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <SummaryCard icon={<Bike className="size-4" />} label="عدد التوصيلات" value={totals.deliveries.toLocaleString('ar')} />
                    <SummaryCard icon={<Users className="size-4" />} label="عدد السائقين" value={totals.providers.toLocaleString('ar')} />
                    <SummaryCard icon={<Coins className="size-4" />} label="جملة قيم التوصيل" value={formatCurrency(totals.fees)} />
                </div>

                {/* التجميع على المدى كلّه لا على الصفحة المعروضة، فالعدد هنا
                    يطابق ما في الكشف أسفله ولو امتدّ على صفحات. */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="text-base">حسب السائق</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DataTable
                            columns={providerColumns}
                            data={byProvider}
                            keyExtractor={(row) => row.providerId}
                            emptyState={<span className="text-muted-foreground">لا توجد توصيلات في هذا المدى</span>}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">تفاصيل التوصيلات</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DataTable
                            rowOffset={Number(deliveries.meta.from ?? 1) - 1}
                            columns={detailColumns}
                            data={deliveries.data}
                            keyExtractor={(row) => row.id}
                            emptyState={<span className="text-muted-foreground">لا توجد توصيلات في هذا المدى</span>}
                        />

                        <TablePagination
                            currentPage={deliveries.meta.current_page as number}
                            totalPages={deliveries.meta.last_page as number}
                            totalItems={deliveries.meta.total as number}
                            from={deliveries.meta.from as number}
                            to={deliveries.meta.to as number}
                            onPageChange={(page) => router.reload({ data: { page } })}
                        />
                    </CardContent>
                </Card>
            </div>

            {settling && (
                <SettleDialog
                    row={settling}
                    categories={expenseCategories.filter((c) => c.branchId === null || c.branchId === settling.branchId)}
                    onClose={() => setSettling(null)}
                />
            )}
            {cancelling?.settlement && <CancelDialog row={cancelling} onClose={() => setCancelling(null)} />}
        </AppLayout>
    );
}

function SettleDialog({ row, categories, onClose }: { row: DeliveryLogRow; categories: Props['expenseCategories']; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({
        amount: row.shippingFee > 0 ? String(row.shippingFee) : '',
        paid_from: '',
        expense_category_id: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(`/shipping/deliveries/${row.id}/settle`, { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        استلام مبلغ التوصيل — <span dir="ltr">{row.invoiceNumber}</span>
                    </DialogTitle>
                </DialogHeader>
                <form id="settle-form" onSubmit={submit} className="space-y-4">
                    <p className="text-muted-foreground text-sm">السائق: {row.providerName ?? '—'}</p>
                    <div className="space-y-1">
                        <Label htmlFor="settle-amount">المبلغ (ر.س)</Label>
                        <Input id="settle-amount" type="number" min="0.01" step="0.01" dir="ltr" value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                        <InputError message={errors.amount} />
                    </div>
                    <div className="space-y-1">
                        <Label>طريقة التسوية</Label>
                        <div className="grid grid-cols-2 gap-2">
                            {SOURCES.map((s) => (
                                <Button
                                    key={s.value}
                                    type="button"
                                    variant={data.paid_from === s.value ? 'default' : 'outline'}
                                    aria-pressed={data.paid_from === s.value}
                                    onClick={() => setData('paid_from', s.value)}
                                >
                                    {s.label}
                                </Button>
                            ))}
                        </div>
                        <InputError message={errors.paid_from} />
                    </div>
                    <div className="space-y-1">
                        <Label>فئة المصروف</Label>
                        <Select value={data.expense_category_id} onValueChange={(v) => setData('expense_category_id', v)}>
                            <SelectTrigger>
                                <SelectValue placeholder="اختر الفئة" />
                            </SelectTrigger>
                            <SelectContent>
                                {categories.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>
                                        {c.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.expense_category_id} />
                    </div>
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="settle-form" disabled={processing}>
                        تسجيل الاستلام
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function CancelDialog({ row, onClose }: { row: DeliveryLogRow; onClose: () => void }) {
    const form = useForm({ reason: '' });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        إلغاء تسوية <span dir="ltr">{row.invoiceNumber}</span>
                    </DialogTitle>
                </DialogHeader>
                <p className="text-muted-foreground text-sm">يُحذف مصروف التسوية ويُتاح تسجيل الاستلام من جديد. السبب يُحفظ في السجلّ.</p>
                <div className="space-y-1">
                    <Label htmlFor="cancel-reason">السبب</Label>
                    <Input id="cancel-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    <InputError message={form.errors.reason} />
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose} disabled={form.processing}>
                        تراجع
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={form.processing}
                        onClick={() => form.delete(`/shipping/settlements/${row.settlement!.expenseId}`, { preserveScroll: true, onSuccess: onClose })}
                    >
                        إلغاء التسوية
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
