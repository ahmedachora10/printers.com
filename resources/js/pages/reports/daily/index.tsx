import { DataTable, type ColumnDef } from '@/components/data-table';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import DateRangeBar from '@/components/reports/date-range-bar';
import { FilterMultiSelect, FilterSelect } from '@/components/reports/filter-fields';
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
import { Banknote, CalendarDays, CreditCard, Download, ShoppingCart, TrendingUp, Wallet } from 'lucide-react';
import { useMemo } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'التقرير اليومي', href: '/reports/daily' }];

const REPORT_URL = '/reports/daily';

const EMPTY_STATE = <span className="text-muted-foreground">لا توجد بيانات مطابقة للتصفية</span>;

interface Props {
    rows: DailyReportRow[];
    totals: DailyReportTotals;
    showPurchases: boolean;
    /** Two or more employees selected: rows are split per employee with a daily total row. */
    detailed: boolean;
    filters: DailyReportFilters;
    /** Today — the date fields' cleared value, since the report opens on today. */
    defaultDate: string;
    branches: { id: number; name: string }[];
    employees: { id: number; name: string }[];
    isSuperAdmin: boolean;
}

export default function DailyReportIndex({ rows, totals, showPurchases, detailed, filters, defaultDate, branches, employees, isSuperAdmin }: Props) {
    const canPickBranch = isSuperAdmin && branches.length > 0;

    // Today is the cleared state of the date fields, so an untouched report shows
    // no date chips and clearing one snaps that end back to today.
    const defaults = useMemo<FilterValues>(() => ({ from: defaultDate, to: defaultDate, branch: 'all', employee: '' }), [defaultDate]);

    const applied: FilterValues = {
        from: filters.from ?? defaultDate,
        to: filters.to ?? defaultDate,
        branch: filters.branch ?? 'all',
        employee: filters.employee ?? '',
    };
    const f = useReportFilters(REPORT_URL, applied, defaults);

    const selectedEmployees = applied.employee ? applied.employee.split(',') : [];

    const qs = new URLSearchParams(f.appliedQuery).toString();
    const exportUrl = `${REPORT_URL}/export${qs ? `?${qs}` : ''}`;

    const chips: FilterChip[] = [];
    // No chips for from/to — the range is always visible in the bar above.
    if (f.isActive('branch')) {
        const name = branches.find((b) => b.id.toString() === applied.branch)?.name ?? applied.branch;
        chips.push({ key: 'branch', label: `الفرع: ${name}`, onRemove: () => f.remove('branch') });
    }
    // One removable chip per selected employee; removing narrows the list.
    for (const id of selectedEmployees) {
        const name = employees.find((e) => e.id.toString() === id)?.name ?? id;
        chips.push({
            key: `employee-${id}`,
            label: `الموظف: ${name}`,
            onRemove: () => f.replace('employee', selectedEmployees.filter((v) => v !== id).join(',')),
        });
    }

    const columns = useMemo<ColumnDef<DailyReportRow>[]>(() => {
        const cols: ColumnDef<DailyReportRow>[] = [{ key: 'date', header: 'التاريخ', className: 'font-medium', cell: (row) => formatDate(row.date) }];

        if (detailed) {
            cols.push({
                key: 'employeeName',
                header: 'الموظف',
                cell: (row) => (row.isTotal ? 'الإجمالي' : (row.employeeName ?? '—')),
            });
        }

        cols.push(
            { key: 'products', header: 'المنتجات', cell: (row) => formatCurrency(row.products) },
            { key: 'services', header: 'الخدمات', cell: (row) => formatCurrency(row.services) },
            { key: 'total', header: 'الإجمالي', className: 'font-semibold text-green-600', cell: (row) => formatCurrency(row.total) },
            // المحصَّل: ما دخل الصندوق فعلاً ذلك اليوم (دفعات الفواتير)، لا ما استُحق.
            { key: 'collected', header: 'المحصَّل', className: 'font-medium text-sky-600', cell: (row) => formatCurrency(row.collected) },
            { key: 'commission', header: 'عمولة الموظفين', className: 'text-amber-600', cell: (row) => formatCurrency(row.commission) },
        );

        if (showPurchases) {
            cols.push({ key: 'purchases', header: 'المشتريات', className: 'text-rose-600', cell: (row) => formatCurrency(row.purchases) });
            cols.push({ key: 'remaining', header: 'المبلغ المتبقي', className: 'font-medium', cell: (row) => formatCurrency(row.remaining) });
        }

        cols.push({ key: 'vat', header: 'الضريبة', className: 'text-muted-foreground', cell: (row) => formatCurrency(row.vat) });

        return cols;
    }, [showPurchases, detailed]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="التقرير اليومي" />
            <div className="p-4 md:p-6">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-bold md:text-2xl">التقرير اليومي</h1>
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
                            <FilterMultiSelect
                                label="الموظف"
                                value={f.draft.employee}
                                onChange={(v) => f.setField('employee', v)}
                                allLabel="كل الموظفين"
                                searchPlaceholder="ابحث عن موظف..."
                                options={employees.map((e) => ({ value: e.id.toString(), label: e.name }))}
                                className="sm:col-span-2"
                            />
                        </FilterModal>
                        <Button asChild variant="outline" disabled={totals.dayCount === 0}>
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

                {/* Summary tiles */}
                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <SummaryCard
                        icon={<TrendingUp className="size-4" />}
                        label="إجمالي المبيعات"
                        value={formatCurrency(totals.total)}
                        valueClass="text-green-600"
                    />
                    <SummaryCard
                        icon={<Banknote className="size-4" />}
                        label="المحصَّل"
                        value={formatCurrency(totals.collected)}
                        valueClass="text-sky-600"
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
                            keyExtractor={(row) => `${row.date}-${row.isTotal ? 'total' : (row.employeeId ?? 'all')}`}
                            rowClassName={(row) => (row.isTotal ? 'bg-muted/40 font-semibold hover:bg-muted/50' : undefined)}
                            emptyState={EMPTY_STATE}
                            footer={
                                <TableRow>
                                    <TableCell className="font-bold">الإجمالي</TableCell>
                                    {detailed && <TableCell />}
                                    <TableCell className="font-bold">{formatCurrency(totals.products)}</TableCell>
                                    <TableCell className="font-bold">{formatCurrency(totals.services)}</TableCell>
                                    <TableCell className="font-bold text-green-600">{formatCurrency(totals.total)}</TableCell>
                                    <TableCell className="font-bold text-sky-600">{formatCurrency(totals.collected)}</TableCell>
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
