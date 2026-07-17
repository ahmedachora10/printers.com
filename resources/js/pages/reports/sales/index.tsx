import { DataTable, type ColumnDef } from '@/components/data-table';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import { DateRangeFields, FilterSelect } from '@/components/reports/filter-fields';
import { FilterModal } from '@/components/reports/filter-modal';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TableCell, TableRow } from '@/components/ui/table';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import {
    type SalesReportBranchRow,
    type SalesReportDayRow,
    type SalesReportEmployeeRow,
    type SalesReportFilters,
    type SalesReportPaymentMethodRow,
    type SalesReportTotals,
    type SalesReportTypeRow,
} from '@/types/sales-report';
import { Head } from '@inertiajs/react';
import { CreditCard, Download, Percent, Receipt, TrendingUp, Wallet } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'تقرير المبيعات', href: '/reports/sales' }];

const REPORT_URL = '/reports/sales';

const DEFAULTS: FilterValues = { from: '', to: '', branch: 'all', type: 'all' };

const TYPE_LABELS: Record<string, string> = { product: 'منتجات', service: 'خدمات' };

const EMPTY_STATE = <span className="text-muted-foreground">لا توجد بيانات مطابقة للتصفية</span>;

const typeColumns: ColumnDef<SalesReportTypeRow>[] = [
    { key: 'label', header: 'النوع', className: 'font-medium', cell: (row) => row.label },
    { key: 'count', header: 'عدد الفواتير', cell: (row) => row.count },
    { key: 'subtotal', header: 'قبل الخصم', cell: (row) => formatCurrency(row.subtotal) },
    { key: 'discounts', header: 'الخصومات', className: 'text-amber-600', cell: (row) => formatCurrency(row.discounts) },
    { key: 'vat', header: 'الضريبة', className: 'text-muted-foreground', cell: (row) => formatCurrency(row.vat) },
    { key: 'total', header: 'الإجمالي', className: 'font-semibold text-green-600', cell: (row) => formatCurrency(row.total) },
];

const breakdownColumns = (nameHeader: string): ColumnDef<BreakdownRow>[] => [
    { key: 'name', header: nameHeader, className: 'font-medium', cell: (row) => row.name },
    { key: 'count', header: 'عدد الفواتير', cell: (row) => row.count },
    { key: 'total', header: 'الإجمالي', className: 'font-medium', cell: (row) => formatCurrency(row.total) },
];

const dayColumns: ColumnDef<SalesReportDayRow>[] = [
    { key: 'date', header: 'التاريخ', cell: (row) => formatDate(row.date) },
    { key: 'count', header: 'عدد الفواتير', cell: (row) => row.count },
    { key: 'total', header: 'الإجمالي', className: 'font-medium', cell: (row) => formatCurrency(row.total) },
];

interface Props {
    totals: SalesReportTotals;
    byType: SalesReportTypeRow[];
    byDay: SalesReportDayRow[];
    byEmployee: SalesReportEmployeeRow[];
    byPaymentMethod: SalesReportPaymentMethodRow[];
    byBranch: SalesReportBranchRow[];
    filters: SalesReportFilters;
    branches: { id: number; name: string }[];
    isSuperAdmin: boolean;
}

export default function SalesReportIndex({ totals, byType, byDay, byEmployee, byPaymentMethod, byBranch, filters, branches, isSuperAdmin }: Props) {
    const canPickBranch = isSuperAdmin && branches.length > 0;

    const applied: FilterValues = {
        from: filters.from ?? '',
        to: filters.to ?? '',
        branch: filters.branch ?? 'all',
        type: filters.type ?? 'all',
    };
    const f = useReportFilters(REPORT_URL, applied, DEFAULTS);

    const qs = new URLSearchParams(f.appliedQuery).toString();
    const exportUrl = `${REPORT_URL}/export${qs ? `?${qs}` : ''}`;

    const chips: FilterChip[] = [];
    if (f.isActive('from')) chips.push({ key: 'from', label: `من: ${formatDate(applied.from)}`, onRemove: () => f.remove('from') });
    if (f.isActive('to')) chips.push({ key: 'to', label: `إلى: ${formatDate(applied.to)}`, onRemove: () => f.remove('to') });
    if (f.isActive('branch')) {
        const name = branches.find((b) => b.id.toString() === applied.branch)?.name ?? applied.branch;
        chips.push({ key: 'branch', label: `الفرع: ${name}`, onRemove: () => f.remove('branch') });
    }
    if (f.isActive('type'))
        chips.push({ key: 'type', label: `النوع: ${TYPE_LABELS[applied.type] ?? applied.type}`, onRemove: () => f.remove('type') });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="تقرير المبيعات" />
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">تقرير المبيعات</h1>
                    <div className="flex items-center gap-2">
                        <FilterModal open={f.open} onOpenChange={f.onOpenChange} onApply={f.apply} onReset={f.reset} activeCount={f.activeCount}>
                            <DateRangeFields
                                from={f.draft.from}
                                to={f.draft.to}
                                onFromChange={(v) => f.setField('from', v)}
                                onToChange={(v) => f.setField('to', v)}
                            />
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
                                label="النوع"
                                value={f.draft.type}
                                onChange={(v) => f.setField('type', v)}
                                options={[
                                    { value: 'product', label: 'منتجات' },
                                    { value: 'service', label: 'خدمات' },
                                ]}
                            />
                        </FilterModal>
                        <Button asChild variant="outline" disabled={totals.invoiceCount === 0}>
                            <a href={exportUrl}>
                                <Download className="size-4" /> تصدير Excel
                            </a>
                        </Button>
                    </div>
                </div>

                <ActiveFilterChips chips={chips} />

                {/* Summary tiles */}
                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <SummaryCard icon={<Receipt className="size-4" />} label="عدد الفواتير" value={totals.invoiceCount.toLocaleString('ar')} />
                    <SummaryCard icon={<TrendingUp className="size-4" />} label="قبل الخصم" value={formatCurrency(totals.subtotal)} />
                    <SummaryCard
                        icon={<Percent className="size-4" />}
                        label="الخصومات"
                        value={formatCurrency(totals.discounts)}
                        valueClass="text-amber-600"
                    />
                    <SummaryCard
                        icon={<CreditCard className="size-4" />}
                        label="الضريبة"
                        value={formatCurrency(totals.vat)}
                        valueClass="text-muted-foreground"
                    />
                    <SummaryCard
                        icon={<Wallet className="size-4" />}
                        label="صافي المبيعات"
                        value={formatCurrency(totals.total)}
                        valueClass="text-green-600"
                    />
                </div>

                {/* By type */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>المبيعات حسب النوع</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={typeColumns}
                            data={byType}
                            keyExtractor={(row) => row.type}
                            emptyState={EMPTY_STATE}
                            footer={
                                <TableRow>
                                    <TableCell className="font-bold">الإجمالي</TableCell>
                                    <TableCell className="font-bold">{totals.invoiceCount}</TableCell>
                                    <TableCell className="font-bold">{formatCurrency(totals.subtotal)}</TableCell>
                                    <TableCell className="font-bold text-amber-600">{formatCurrency(totals.discounts)}</TableCell>
                                    <TableCell className="text-muted-foreground font-bold">{formatCurrency(totals.vat)}</TableCell>
                                    <TableCell className="font-bold text-green-600">{formatCurrency(totals.total)}</TableCell>
                                </TableRow>
                            }
                        />
                    </CardContent>
                </Card>

                {isSuperAdmin && (
                    <BreakdownCard
                        title="المبيعات حسب الفرع"
                        nameHeader="الفرع"
                        rows={byBranch.map((b) => ({ key: b.branchId, name: b.branchName, count: b.count, total: b.total }))}
                    />
                )}

                <BreakdownCard
                    title="المبيعات حسب الموظف"
                    nameHeader="الموظف"
                    rows={byEmployee.map((e) => ({ key: e.userId, name: e.userName, count: e.count, total: e.total }))}
                />

                <BreakdownCard
                    title="المبيعات حسب طريقة الدفع"
                    nameHeader="طريقة الدفع"
                    rows={byPaymentMethod.map((m) => ({ key: m.methodId ?? 0, name: m.methodName, count: m.count, total: m.total }))}
                />

                {/* By day */}
                <Card>
                    <CardHeader>
                        <CardTitle>المبيعات اليومية</CardTitle>
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
            </div>
        </AppLayout>
    );
}

function SummaryCard({ icon, label, value, valueClass }: { icon: React.ReactNode; label: string; value: string; valueClass?: string }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-muted-foreground flex items-center gap-2 text-sm font-medium">
                    {icon} {label}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className={`text-2xl font-bold ${valueClass ?? ''}`}>{value}</p>
            </CardContent>
        </Card>
    );
}

interface BreakdownRow {
    key: number;
    name: string;
    count: number;
    total: number;
}

function BreakdownCard({ title, nameHeader, rows }: { title: string; nameHeader: string; rows: BreakdownRow[] }) {
    const total = rows.reduce((sum, r) => sum + r.total, 0);

    return (
        <Card className="mb-6">
            <CardHeader>
                <CardTitle>{title}</CardTitle>
            </CardHeader>
            <CardContent className="p-0">
                <DataTable
                    className="rounded-none bg-transparent shadow-none"
                    columns={breakdownColumns(nameHeader)}
                    data={rows}
                    keyExtractor={(row) => row.key}
                    emptyState={EMPTY_STATE}
                    footer={
                        <TableRow>
                            <TableCell className="font-bold">الإجمالي</TableCell>
                            <TableCell />
                            <TableCell className="font-bold text-green-600">{formatCurrency(total)}</TableCell>
                        </TableRow>
                    }
                />
            </CardContent>
        </Card>
    );
}
