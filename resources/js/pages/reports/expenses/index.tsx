import { DataTable, type ColumnDef } from '@/components/data-table';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import DateRangeBar from '@/components/reports/date-range-bar';
import { FilterSelect } from '@/components/reports/filter-fields';
import { FilterModal } from '@/components/reports/filter-modal';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TableCell, TableRow } from '@/components/ui/table';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import {
    type ExpenseReportCategoryRow,
    type ExpenseReportDayRow,
    type ExpenseReportFilters,
    type ExpenseReportRow,
    type ExpenseReportTotals,
} from '@/types/expense-report';
import { Head } from '@inertiajs/react';
import { Download, FolderKanban, Receipt, Sigma, Wallet } from 'lucide-react';
import { useMemo } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'تقرير المصروفات', href: '/reports/expenses' }];

const REPORT_URL = '/reports/expenses';

const EMPTY_STATE = <span className="text-muted-foreground">لا توجد مصروفات مطابقة للتصفية</span>;

const categoryColumns: ColumnDef<ExpenseReportCategoryRow>[] = [
    { key: 'name', header: 'الفئة', className: 'font-medium', cell: (row) => row.name },
    { key: 'count', header: 'عدد العمليات', cell: (row) => row.count },
    { key: 'total', header: 'الإجمالي', className: 'font-medium', cell: (row) => formatCurrency(row.total) },
    {
        key: 'pct',
        header: 'النسبة',
        className: 'text-muted-foreground',
        cell: (row) => <span dir="ltr">{row.pct.toFixed(2)}%</span>,
    },
];

const dayColumns: ColumnDef<ExpenseReportDayRow>[] = [
    { key: 'date', header: 'التاريخ', cell: (row) => formatDate(row.date) },
    { key: 'count', header: 'عدد العمليات', cell: (row) => row.count },
    { key: 'total', header: 'الإجمالي', className: 'font-medium', cell: (row) => formatCurrency(row.total) },
];

const dash = <span className="text-muted-foreground">—</span>;

interface Props {
    totals: ExpenseReportTotals;
    byCategory: ExpenseReportCategoryRow[];
    byDay: ExpenseReportDayRow[];
    expenses: ExpenseReportRow[];
    filters: ExpenseReportFilters;
    /** Today — the date fields' cleared value, since the report opens on today. */
    defaultDate: string;
    branches: { id: number; name: string }[];
    categories: { id: number; name: string }[];
    isSuperAdmin: boolean;
}

export default function ExpenseReportIndex({
    totals,
    byCategory,
    byDay,
    expenses,
    filters,
    defaultDate,
    branches,
    categories,
    isSuperAdmin,
}: Props) {
    const canPickBranch = isSuperAdmin && branches.length > 0;

    // Today is the cleared state of the date fields, so an untouched report shows
    // no date chips and clearing one snaps that end back to today.
    const defaults = useMemo<FilterValues>(() => ({ from: defaultDate, to: defaultDate, branch: 'all', category: 'all' }), [defaultDate]);

    const applied: FilterValues = {
        from: filters.from ?? defaultDate,
        to: filters.to ?? defaultDate,
        branch: filters.branch ?? 'all',
        category: filters.category ?? 'all',
    };
    const f = useReportFilters(REPORT_URL, applied, defaults);

    const qs = new URLSearchParams(f.appliedQuery).toString();
    const exportUrl = `${REPORT_URL}/export${qs ? `?${qs}` : ''}`;

    // The branch column only carries information when several branches can show
    // up at once — every other role is pinned to one.
    const detailColumns = useMemo<ColumnDef<ExpenseReportRow>[]>(
        () => [
            { key: 'date', header: 'التاريخ', cell: (row) => formatDate(row.date) },
            { key: 'categoryName', header: 'الفئة', className: 'font-medium', cell: (row) => row.categoryName },
            ...(isSuperAdmin
                ? [{ key: 'branchName', header: 'الفرع', cell: (row: ExpenseReportRow) => row.branchName ?? dash }]
                : []),
            { key: 'supplierName', header: 'المورّد', cell: (row) => row.supplierName ?? dash },
            {
                key: 'qty',
                header: 'الكمية',
                cell: (row) => (
                    <span className="tabular-nums" dir="ltr">
                        {row.qty}
                    </span>
                ),
            },
            { key: 'unitPrice', header: 'سعر الوحدة', cell: (row) => formatCurrency(row.unitPrice) },
            { key: 'total', header: 'الإجمالي', className: 'font-semibold', cell: (row) => formatCurrency(row.total) },
            { key: 'receiptReference', header: 'المرجع', cell: (row) => row.receiptReference ?? dash },
            { key: 'userName', header: 'مَن سجّلها', cell: (row) => row.userName ?? dash },
        ],
        [isSuperAdmin],
    );

    // No chips for from/to — the range is always visible in the bar above.
    const chips: FilterChip[] = [];
    if (f.isActive('branch')) {
        const name = branches.find((b) => b.id.toString() === applied.branch)?.name ?? applied.branch;
        chips.push({ key: 'branch', label: `الفرع: ${name}`, onRemove: () => f.remove('branch') });
    }
    if (f.isActive('category')) {
        const name = categories.find((c) => c.id.toString() === applied.category)?.name ?? applied.category;
        chips.push({ key: 'category', label: `الفئة: ${name}`, onRemove: () => f.remove('category') });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="تقرير المصروفات" />
            <div className="p-4 md:p-6">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-bold md:text-2xl">تقرير المصروفات</h1>
                    <div className="flex items-center gap-2">
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
                                label="الفئة"
                                value={f.draft.category}
                                onChange={(v) => f.setField('category', v)}
                                allLabel="كل الفئات"
                                options={categories.map((c) => ({ value: c.id.toString(), label: c.name }))}
                            />
                        </FilterModal>
                        <Button asChild variant="outline" disabled={totals.expenseCount === 0}>
                            <a href={exportUrl}>
                                <Download className="size-4" /> تصدير Excel
                            </a>
                        </Button>
                    </div>
                </div>

                <div className="mb-6">
                    <DateRangeBar filters={f} from={applied.from} to={applied.to} />
                </div>

                <ActiveFilterChips chips={chips} />

                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <SummaryCard
                        icon={<Wallet className="size-4" />}
                        label="إجمالي المصروفات"
                        value={formatCurrency(totals.total)}
                        valueClass="text-amber-600"
                    />
                    <SummaryCard icon={<Receipt className="size-4" />} label="عدد العمليات" value={totals.expenseCount.toLocaleString('ar')} />
                    <SummaryCard icon={<Sigma className="size-4" />} label="متوسط العملية" value={formatCurrency(totals.average)} />
                    <SummaryCard
                        icon={<FolderKanban className="size-4" />}
                        label="أعلى فئة"
                        value={totals.topCategoryName ?? '—'}
                        hint={totals.topCategoryName ? formatCurrency(totals.topCategoryTotal) : undefined}
                    />
                </div>

                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>المصروفات حسب الفئة</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={categoryColumns}
                            data={byCategory}
                            keyExtractor={(row) => row.categoryId ?? 0}
                            emptyState={EMPTY_STATE}
                            footer={
                                <TableRow>
                                    <TableCell className="font-bold">الإجمالي</TableCell>
                                    <TableCell className="font-bold">{totals.expenseCount}</TableCell>
                                    <TableCell className="font-bold text-amber-600">{formatCurrency(totals.total)}</TableCell>
                                    <TableCell />
                                </TableRow>
                            }
                        />
                    </CardContent>
                </Card>

                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>المصروفات اليومية</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={dayColumns}
                            data={byDay}
                            keyExtractor={(row) => row.date}
                            emptyState={EMPTY_STATE}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>تفاصيل العمليات</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={detailColumns}
                            data={expenses}
                            keyExtractor={(row) => row.id}
                            emptyState={EMPTY_STATE}
                        />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

function SummaryCard({
    icon,
    label,
    value,
    valueClass,
    hint,
}: {
    icon: React.ReactNode;
    label: string;
    value: string;
    valueClass?: string;
    hint?: string;
}) {
    return (
        <Card className="min-w-0">
            <CardHeader className="pb-2">
                <CardTitle className="text-muted-foreground flex items-center gap-2 text-sm font-medium">
                    <span className="shrink-0">{icon}</span>
                    <span className="truncate">{label}</span>
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className={`truncate text-xl font-bold sm:text-2xl ${valueClass ?? ''}`}>{value}</p>
                {hint && <p className="text-muted-foreground mt-1 truncate text-sm tabular-nums">{hint}</p>}
            </CardContent>
        </Card>
    );
}
