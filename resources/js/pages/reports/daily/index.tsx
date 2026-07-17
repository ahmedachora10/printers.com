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
import { type DailyReportFilters, type DailyReportRow, type DailyReportTotals } from '@/types/daily-report';
import { Head } from '@inertiajs/react';
import { CalendarDays, CreditCard, Download, ShoppingCart, TrendingUp, Wallet } from 'lucide-react';
import { useMemo } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'التقرير اليومي', href: '/reports/daily' }];

const REPORT_URL = '/reports/daily';

const DEFAULTS: FilterValues = { from: '', to: '', branch: 'all', employee: 'all' };

const EMPTY_STATE = <span className="text-muted-foreground">لا توجد بيانات مطابقة للتصفية</span>;

interface Props {
    rows: DailyReportRow[];
    totals: DailyReportTotals;
    showPurchases: boolean;
    filters: DailyReportFilters;
    branches: { id: number; name: string }[];
    employees: { id: number; name: string }[];
    isSuperAdmin: boolean;
}

export default function DailyReportIndex({ rows, totals, showPurchases, filters, branches, employees, isSuperAdmin }: Props) {
    const canPickBranch = isSuperAdmin && branches.length > 0;

    const applied: FilterValues = {
        from: filters.from ?? '',
        to: filters.to ?? '',
        branch: filters.branch ?? 'all',
        employee: filters.employee ?? 'all',
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
    if (f.isActive('employee')) {
        const name = employees.find((e) => e.id.toString() === applied.employee)?.name ?? applied.employee;
        chips.push({ key: 'employee', label: `الموظف: ${name}`, onRemove: () => f.remove('employee') });
    }

    const columns = useMemo<ColumnDef<DailyReportRow>[]>(() => {
        const cols: ColumnDef<DailyReportRow>[] = [
            { key: 'date', header: 'التاريخ', className: 'font-medium', cell: (row) => formatDate(row.date) },
            { key: 'products', header: 'المنتجات', cell: (row) => formatCurrency(row.products) },
            { key: 'services', header: 'الخدمات', cell: (row) => formatCurrency(row.services) },
            { key: 'total', header: 'الإجمالي', className: 'font-semibold text-green-600', cell: (row) => formatCurrency(row.total) },
            { key: 'commission', header: 'عمولة الموظفين', className: 'text-amber-600', cell: (row) => formatCurrency(row.commission) },
        ];

        if (showPurchases) {
            cols.push({ key: 'purchases', header: 'المشتريات', className: 'text-rose-600', cell: (row) => formatCurrency(row.purchases) });
            cols.push({ key: 'remaining', header: 'المبلغ المتبقي', className: 'font-medium', cell: (row) => formatCurrency(row.remaining) });
        }

        cols.push({ key: 'vat', header: 'الضريبة', className: 'text-muted-foreground', cell: (row) => formatCurrency(row.vat) });

        return cols;
    }, [showPurchases]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="التقرير اليومي" />
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">التقرير اليومي</h1>
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
                                label="الموظف"
                                value={f.draft.employee}
                                onChange={(v) => f.setField('employee', v)}
                                allLabel="كل الموظفين"
                                options={employees.map((e) => ({ value: e.id.toString(), label: e.name }))}
                            />
                        </FilterModal>
                        <Button asChild variant="outline" disabled={totals.dayCount === 0}>
                            <a href={exportUrl}>
                                <Download className="size-4" /> تصدير Excel
                            </a>
                        </Button>
                    </div>
                </div>

                <ActiveFilterChips chips={chips} />

                {/* Summary tiles */}
                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <SummaryCard
                        icon={<TrendingUp className="size-4" />}
                        label="إجمالي المبيعات"
                        value={formatCurrency(totals.total)}
                        valueClass="text-green-600"
                    />
                    <SummaryCard
                        icon={<Wallet className="size-4" />}
                        label="عمولة الموظفين"
                        value={formatCurrency(totals.commission)}
                        valueClass="text-amber-600"
                    />
                    {showPurchases && (
                        <SummaryCard
                            icon={<ShoppingCart className="size-4" />}
                            label="المشتريات"
                            value={formatCurrency(totals.purchases)}
                            valueClass="text-rose-600"
                        />
                    )}
                    {showPurchases && (
                        <SummaryCard icon={<CalendarDays className="size-4" />} label="المبلغ المتبقي" value={formatCurrency(totals.remaining)} />
                    )}
                    <SummaryCard
                        icon={<CreditCard className="size-4" />}
                        label="الضريبة"
                        value={formatCurrency(totals.vat)}
                        valueClass="text-muted-foreground"
                    />
                </div>

                {/* Daily table */}
                <Card>
                    <CardHeader>
                        <CardTitle>الحركة اليومية</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={columns}
                            data={rows}
                            keyExtractor={(row) => row.date}
                            emptyState={EMPTY_STATE}
                            footer={
                                <TableRow>
                                    <TableCell className="font-bold">الإجمالي</TableCell>
                                    <TableCell className="font-bold">{formatCurrency(totals.products)}</TableCell>
                                    <TableCell className="font-bold">{formatCurrency(totals.services)}</TableCell>
                                    <TableCell className="font-bold text-green-600">{formatCurrency(totals.total)}</TableCell>
                                    <TableCell className="font-bold text-amber-600">{formatCurrency(totals.commission)}</TableCell>
                                    {showPurchases && <TableCell className="font-bold text-rose-600">{formatCurrency(totals.purchases)}</TableCell>}
                                    {showPurchases && <TableCell className="font-bold">{formatCurrency(totals.remaining)}</TableCell>}
                                    <TableCell className="text-muted-foreground font-bold">{formatCurrency(totals.vat)}</TableCell>
                                </TableRow>
                            }
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
