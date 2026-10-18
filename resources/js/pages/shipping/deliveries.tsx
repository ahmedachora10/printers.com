import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { CancelSettlementDialog, SettleDeliveryDialog } from '@/components/shipping/settle-dialogs';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import DateRangeBar from '@/components/reports/date-range-bar';
import { FilterSelect } from '@/components/reports/filter-fields';
import { FilterModal } from '@/components/reports/filter-modal';
import { SummaryCard } from '@/components/reports/summary-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type BankAccountOption } from '@/types/expense';
import {
    type DeliveryLogFilters,
    type DeliveryLogProviderRow,
    type DeliveryLogRow,
    type DeliveryLogTotals,
} from '@/types/delivery-log';
import { Head, router } from '@inertiajs/react';
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
    /** تاسك 187 */
    bankAccounts: BankAccountOption[];
    branches: { id: number; name: string }[];
    isSuperAdmin: boolean;
    /** تاسك 127: المحاسب يقرأ الكشف ولا يسوّي أجر السائق. */
    canSettle: boolean;
    defaultDate: string;
    filters: DeliveryLogFilters;
}

/**
 * تاسك 93 — ما على كل سائق اليوم: كم رحلة، وإلى أين، وبكم.
 *
 * تاسك 111: «تسليم مبلغ التوصيل» لكل طلب — مصروفٌ مربوطٌ بالطلب. لا أرصدة
 * للسائقين ولا دفعات مجمَّعة.
 */
export default function DeliveriesIndex({
    deliveries,
    byProvider,
    totals,
    providers,
    expenseCategories,
    bankAccounts,
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
            { key: 'employeeName', header: 'الموظف', cell: (row) => row.employeeName ?? dash },
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
                            <HandCoins className="size-4" /> تسليم مبلغ التوصيل
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
                <SettleDeliveryDialog
                    row={settling}
                    categories={expenseCategories.filter((c) => c.branchId === null || c.branchId === settling.branchId)}
                    bankAccounts={bankAccounts.filter((a) => a.branchId === settling.branchId)}
                    onClose={() => setSettling(null)}
                />
            )}
            {cancelling?.settlement && <CancelSettlementDialog row={cancelling} onClose={() => setCancelling(null)} />}
        </AppLayout>
    );
}
