import { DataTable, type ColumnDef } from '@/components/data-table';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import DateRangeBar from '@/components/reports/date-range-bar';
import { FilterSelect } from '@/components/reports/filter-fields';
import { FilterModal } from '@/components/reports/filter-modal';
import { SummaryCard } from '@/components/reports/summary-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TableCell, TableRow } from '@/components/ui/table';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type EnumOption } from '@/types/incentive';
import {
    type IncentiveReportDeductionRow,
    type IncentiveReportFilters,
    type IncentiveReportPlanRow,
    type IncentiveReportReasonRow,
    type IncentiveReportSummaryRow,
    type IncentiveReportTotals,
} from '@/types/report';
import { Banknote, Download, Minus, Scale, Target, Trophy, Users } from 'lucide-react';
import { useMemo } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'تقرير الحوافز والخصومات', href: '/reports/incentives' }];

const REPORT_URL = '/reports/incentives';

const STATUS_VARIANT: Record<string, 'secondary' | 'default' | 'outline' | 'destructive'> = {
    active: 'secondary',
    achieved: 'default',
    missed: 'destructive',
    paid: 'outline',
};

interface Props {
    summary: IncentiveReportSummaryRow[];
    totals: IncentiveReportTotals;
    byReason: IncentiveReportReasonRow[];
    plans: IncentiveReportPlanRow[];
    deductions: IncentiveReportDeductionRow[];
    filters: IncentiveReportFilters;
    /** المدى الافتراضي — الشهر الجاري، وهو الحالة «الفارغة» لحقلَي التاريخ. */
    defaultFrom: string;
    defaultTo: string;
    statuses: EnumOption[];
    employees: { id: number; name: string }[];
    branches: { id: number; name: string }[];
    isSuperAdmin: boolean;
}

export default function IncentiveReportIndex({
    summary,
    totals,
    byReason,
    plans,
    deductions,
    filters,
    defaultFrom,
    defaultTo,
    statuses,
    employees,
    branches,
    isSuperAdmin,
}: Props) {
    const canPickBranch = isSuperAdmin && branches.length > 0;
    const canPickEmployee = employees.length > 0;

    const defaults = useMemo<FilterValues>(
        () => ({ from: defaultFrom, to: defaultTo, employee: 'all', branch: 'all', status: 'all' }),
        [defaultFrom, defaultTo],
    );

    const applied: FilterValues = {
        from: filters.from ?? defaultFrom,
        to: filters.to ?? defaultTo,
        employee: filters.employee ?? 'all',
        branch: filters.branch ?? 'all',
        status: filters.status ?? 'all',
    };
    const f = useReportFilters(REPORT_URL, applied, defaults);

    const qs = new URLSearchParams(f.appliedQuery).toString();
    const exportUrl = `${REPORT_URL}/export${qs ? `?${qs}` : ''}`;

    // لا شرائح للتاريخ — المدى ظاهرٌ دائماً في الشريط أعلاه.
    const chips: FilterChip[] = [];
    if (f.isActive('branch')) {
        const name = branches.find((b) => b.id.toString() === applied.branch)?.name ?? applied.branch;
        chips.push({ key: 'branch', label: `الفرع: ${name}`, onRemove: () => f.remove('branch') });
    }
    if (f.isActive('employee')) {
        const name = employees.find((e) => e.id.toString() === applied.employee)?.name ?? applied.employee;
        chips.push({ key: 'employee', label: `الموظف: ${name}`, onRemove: () => f.remove('employee') });
    }
    if (f.isActive('status')) {
        const label = statuses.find((s) => s.value === applied.status)?.label ?? applied.status;
        chips.push({ key: 'status', label: `حالة الخطة: ${label}`, onRemove: () => f.remove('status') });
    }

    const plansByUser = useMemo(() => groupBy(plans), [plans]);
    const deductionsByUser = useMemo(() => groupBy(deductions), [deductions]);

    const summaryColumns = useMemo<ColumnDef<IncentiveReportSummaryRow>[]>(
        () => [
            { key: 'userName', header: 'الموظف', className: 'font-medium', cell: (row) => row.userName ?? '—' },
            ...(isSuperAdmin ? [{ key: 'branchName', header: 'الفرع', cell: (row: IncentiveReportSummaryRow) => row.branchName ?? '—' }] : []),
            { key: 'planCount', header: 'عدد الخطط', cell: (row) => row.planCount },
            { key: 'target', header: 'المستهدف', cell: (row) => formatCurrency(row.target) },
            {
                key: 'achieved',
                header: 'المحقق',
                cell: (row) => (
                    <div className="min-w-28">
                        <div className="flex items-center justify-between gap-2 text-sm">
                            <span className="tabular-nums">{formatCurrency(row.achieved)}</span>
                            <span className="text-muted-foreground text-xs tabular-nums">{row.progressPct}%</span>
                        </div>
                        <ProgressBar pct={row.progressPct} />
                    </div>
                ),
            },
            { key: 'bonusEarned', header: 'المكافآت المستحقة', cell: (row) => formatCurrency(row.bonusEarned) },
            { key: 'bonusPaid', header: 'المصروف', className: 'text-green-600', cell: (row) => formatCurrency(row.bonusPaid) },
            {
                key: 'deductions',
                header: 'الخصومات',
                cell: (row) =>
                    row.deductions > 0 ? (
                        <span className="text-destructive font-semibold tabular-nums">{formatCurrency(row.deductions)}</span>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    ),
            },
            {
                key: 'net',
                header: 'الصافي',
                cell: (row) => <span className={`font-semibold tabular-nums ${row.net < 0 ? 'text-destructive' : ''}`}>{formatCurrency(row.net)}</span>,
            },
        ],
        [isSuperAdmin],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-4 md:p-6">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-bold md:text-2xl">تقرير الحوافز والخصومات</h1>
                        <p className="text-muted-foreground text-sm">ما استُهدف من الموظف وما صُرف له، وما حُسم عليه — والصافي بينهما.</p>
                    </div>
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
                            {canPickEmployee && (
                                <FilterSelect
                                    label="الموظف"
                                    value={f.draft.employee}
                                    onChange={(v) => f.setField('employee', v)}
                                    allLabel="كل الموظفين"
                                    options={employees.map((e) => ({ value: e.id.toString(), label: e.name }))}
                                />
                            )}
                            <FilterSelect
                                label="حالة الخطة"
                                value={f.draft.status}
                                onChange={(v) => f.setField('status', v)}
                                allLabel="كل الحالات"
                                options={statuses}
                            />
                        </FilterModal>
                        <Button asChild variant="outline" disabled={summary.length === 0}>
                            <a href={exportUrl}>
                                <Download className="size-4" /> تصدير Excel
                            </a>
                        </Button>
                    </div>
                </div>

                <div className="mb-6">
                    <DateRangeBar filters={f} from={applied.from} to={applied.to} extended />
                </div>

                <ActiveFilterChips chips={chips} />

                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <SummaryCard icon={<Users className="size-4" />} label="الموظفون" value={totals.employeeCount.toLocaleString('ar')} hint={`${totals.planCount} خطة`} />
                    <SummaryCard icon={<Target className="size-4" />} label="المستهدف" value={formatCurrency(totals.target)} hint={`الإنجاز ${totals.progressPct}%`} />
                    <SummaryCard icon={<Trophy className="size-4" />} label="المكافآت المستحقة" value={formatCurrency(totals.bonusEarned)} />
                    <SummaryCard icon={<Banknote className="size-4" />} label="المكافآت المصروفة" value={formatCurrency(totals.bonusPaid)} valueClass="text-green-600" />
                    <SummaryCard
                        icon={<Minus className="size-4" />}
                        label="الخصومات"
                        value={formatCurrency(totals.deductions)}
                        valueClass="text-destructive"
                        hint={`${totals.deductionCount} حسم`}
                    />
                    <SummaryCard
                        icon={<Scale className="size-4" />}
                        label="الصافي"
                        value={formatCurrency(totals.net)}
                        valueClass={totals.net < 0 ? 'text-destructive' : ''}
                    />
                </div>

                {byReason.length > 0 && (
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>الخصومات حسب السبب</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <DataTable
                                className="rounded-none bg-transparent shadow-none"
                                columns={reasonColumns}
                                data={byReason}
                                keyExtractor={(row) => row.reason}
                                footer={
                                    <TableRow>
                                        <TableCell className="font-bold">الإجمالي</TableCell>
                                        <TableCell className="font-bold">{totals.deductionCount}</TableCell>
                                        <TableCell className="text-destructive font-bold">{formatCurrency(totals.deductions)}</TableCell>
                                    </TableRow>
                                }
                            />
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>الحوافز والخصومات حسب الموظف</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={summaryColumns}
                            data={summary}
                            keyExtractor={(row) => row.userId}
                            emptyState={<span className="text-muted-foreground">لا توجد حوافز ولا حسومات ضمن الفترة المحددة</span>}
                            renderSubRow={(row) => (
                                <EmployeeDetail plans={plansByUser.get(row.userId) ?? []} deductions={deductionsByUser.get(row.userId) ?? []} />
                            )}
                            footer={
                                <TableRow>
                                    <TableCell />
                                    <TableCell className="font-bold">الإجمالي</TableCell>
                                    {isSuperAdmin && <TableCell />}
                                    <TableCell className="font-bold">{totals.planCount}</TableCell>
                                    <TableCell className="font-bold">{formatCurrency(totals.target)}</TableCell>
                                    <TableCell className="font-bold">{formatCurrency(totals.achieved)}</TableCell>
                                    <TableCell className="font-bold">{formatCurrency(totals.bonusEarned)}</TableCell>
                                    <TableCell className="font-bold text-green-600">{formatCurrency(totals.bonusPaid)}</TableCell>
                                    <TableCell className="text-destructive font-bold">{formatCurrency(totals.deductions)}</TableCell>
                                    <TableCell className="font-bold">{formatCurrency(totals.net)}</TableCell>
                                </TableRow>
                            }
                        />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

const reasonColumns: ColumnDef<IncentiveReportReasonRow>[] = [
    { key: 'reasonLabel', header: 'السبب', className: 'font-medium', cell: (row) => row.reasonLabel },
    { key: 'count', header: 'عدد الخصومات', cell: (row) => row.count },
    { key: 'amount', header: 'القيمة', className: 'text-destructive', cell: (row) => formatCurrency(row.amount) },
];

const planColumns: ColumnDef<IncentiveReportPlanRow>[] = [
    { key: 'periodLabel', header: 'الفترة', className: 'font-medium', cell: (row) => <span dir="ltr">{row.periodLabel}</span> },
    { key: 'target', header: 'المستهدف', cell: (row) => formatCurrency(row.target) },
    { key: 'achieved', header: 'المحقق', cell: (row) => `${formatCurrency(row.achieved)} (${row.progressPct}%)` },
    { key: 'bonusAmount', header: 'المكافأة', cell: (row) => formatCurrency(row.bonusAmount) },
    { key: 'bonusPaid', header: 'المصروف', className: 'text-green-600', cell: (row) => formatCurrency(row.bonusPaid) },
    { key: 'status', header: 'الحالة', cell: (row) => <Badge variant={STATUS_VARIANT[row.status] ?? 'secondary'}>{row.statusLabel}</Badge> },
];

const deductionColumns: ColumnDef<IncentiveReportDeductionRow>[] = [
    { key: 'deductedAt', header: 'التاريخ', className: 'text-sm', cell: (row) => (row.deductedAt ? formatDate(row.deductedAt) : '—') },
    { key: 'amount', header: 'القيمة', className: 'text-destructive font-semibold', cell: (row) => formatCurrency(row.amount) },
    { key: 'reasonText', header: 'السبب', cell: (row) => row.reasonText },
    { key: 'deductedBy', header: 'بواسطة', cell: (row) => row.deductedBy ?? '—' },
    { key: 'notes', header: 'ملاحظات', className: 'text-sm text-muted-foreground', cell: (row) => row.notes ?? '—' },
];

/** تفصيل الموظف داخل صفّه: خططه ثم حسوماته، كلٌّ بترويسته. */
function EmployeeDetail({ plans, deductions }: { plans: IncentiveReportPlanRow[]; deductions: IncentiveReportDeductionRow[] }) {
    return (
        <div className="space-y-4 p-2">
            <div>
                <p className="mb-2 text-sm font-semibold">خطط الحوافز</p>
                <DataTable
                    className="bg-transparent shadow-none"
                    columns={planColumns}
                    data={plans}
                    keyExtractor={(row) => row.id}
                    emptyState={<span className="text-muted-foreground text-sm">لا توجد خطط ضمن الفترة</span>}
                />
            </div>
            <div>
                <p className="mb-2 text-sm font-semibold">الخصومات</p>
                <DataTable
                    className="bg-transparent shadow-none"
                    columns={deductionColumns}
                    data={deductions}
                    keyExtractor={(row) => row.id}
                    emptyState={<span className="text-muted-foreground text-sm">لا توجد حسومات ضمن الفترة</span>}
                />
            </div>
        </div>
    );
}

function ProgressBar({ pct }: { pct: number }) {
    return (
        <div className="bg-muted mt-1 h-1.5 w-full overflow-hidden rounded-full">
            <div className={`h-full rounded-full ${pct >= 100 ? 'bg-green-600' : 'bg-primary'}`} style={{ width: `${Math.min(100, pct)}%` }} />
        </div>
    );
}

/** فهرسة صفوف التفصيل بمعرِّف الموظف — الجدولان يُبنيان مرّةً لا مرّةً لكل صفّ. */
function groupBy<T extends { userId: number }>(rows: T[]): Map<number, T[]> {
    const map = new Map<number, T[]>();
    for (const row of rows) {
        const list = map.get(row.userId) ?? [];
        list.push(row);
        map.set(row.userId, list);
    }
    return map;
}
