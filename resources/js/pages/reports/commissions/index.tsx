import { DataTable, type ColumnDef } from '@/components/data-table';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import { DateRangeFields, FilterSelect } from '@/components/reports/filter-fields';
import { FilterModal } from '@/components/reports/filter-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TableCell, TableRow } from '@/components/ui/table';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import {
    type CommissionReportFilters,
    type CommissionReportLine,
    type CommissionReportSummaryRow,
    type CommissionReportTotals,
} from '@/types/report';
import { Banknote, Download, TrendingUp, Wallet } from 'lucide-react';
import { useMemo } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'تقرير العمولات', href: '/reports/commissions' }];

const DEFAULTS: FilterValues = { from: '', to: '', employee: 'all', branch: 'all', status: 'all' };

const STATUS_LABELS: Record<string, string> = { pending: 'معلقة', paid: 'مصروفة' };

interface Props {
    summary: CommissionReportSummaryRow[];
    lines: CommissionReportLine[];
    totals: CommissionReportTotals;
    filters: CommissionReportFilters;
    employees: { id: number; name: string }[];
    branches: { id: number; name: string }[];
    isSuperAdmin: boolean;
}

const REPORT_URL = '/reports/commissions';

const summaryColumns: ColumnDef<CommissionReportSummaryRow>[] = [
    { key: 'userName', header: 'الموظف', className: 'font-medium', cell: (row) => row.userName },
    { key: 'lineCount', header: 'عدد البنود', cell: (row) => row.lineCount },
    { key: 'earned', header: 'إجمالي العمولة', cell: (row) => formatCurrency(row.earned) },
    { key: 'tahazir', header: 'منها تحضير', className: 'text-muted-foreground', cell: (row) => formatCurrency(row.tahazir) },
    { key: 'paid', header: 'المصروف', className: 'text-green-600', cell: (row) => formatCurrency(row.paid) },
    {
        key: 'pending',
        header: 'المستحق',
        cell: (row) =>
            row.pending > 0 ? (
                <span className="font-semibold text-amber-600">{formatCurrency(row.pending)}</span>
            ) : (
                <span className="text-muted-foreground">—</span>
            ),
    },
];

const detailColumns: ColumnDef<CommissionReportLine>[] = [
    {
        key: 'invoiceNumber',
        header: 'رقم الفاتورة',
        className: 'font-mono text-xs',
        cell: (line) => <span dir="ltr">{line.invoiceNumber}</span>,
    },
    {
        key: 'serviceName',
        header: 'الخدمة',
        cell: (line) => (
            <>
                {line.serviceName}
                {line.isTahazir && (
                    <Badge variant="secondary" className="ms-2">
                        تحضير
                    </Badge>
                )}
            </>
        ),
    },
    { key: 'sourceLabel', header: 'المصدر', className: 'text-sm text-muted-foreground', cell: (line) => line.sourceLabel },
    { key: 'tierApplied', header: 'الشريحة', cell: (line) => line.tierApplied ?? '—' },
    { key: 'amount', header: 'المبلغ', cell: (line) => formatCurrency(line.amount) },
    {
        key: 'status',
        header: 'الحالة',
        cell: (line) => <InvoiceStatusBadge status={line.invoiceStatus} />,
    },
    { key: 'earnedAt', header: 'تاريخ الاستحقاق', className: 'text-sm', cell: (line) => (line.earnedAt ? formatDate(line.earnedAt) : '—') },
];

export default function CommissionReportIndex({ summary, lines, totals, filters, employees, branches, isSuperAdmin }: Props) {
    const canPickEmployee = employees.length > 0;
    const canPickBranch = isSuperAdmin && branches.length > 0;

    const applied: FilterValues = {
        from: filters.from ?? '',
        to: filters.to ?? '',
        employee: filters.employee ?? 'all',
        branch: filters.branch ?? 'all',
        status: filters.status ?? 'all',
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
    if (f.isActive('status')) {
        chips.push({ key: 'status', label: `الحالة: ${STATUS_LABELS[applied.status] ?? applied.status}`, onRemove: () => f.remove('status') });
    }

    const linesByUser = useMemo(() => {
        const map = new Map<number, CommissionReportLine[]>();
        for (const line of lines) {
            const list = map.get(line.userId) ?? [];
            list.push(line);
            map.set(line.userId, list);
        }
        return map;
    }, [lines]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">تقرير العمولات</h1>
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
                                label="الحالة"
                                value={f.draft.status}
                                onChange={(v) => f.setField('status', v)}
                                options={[
                                    { value: 'pending', label: 'معلقة' },
                                    { value: 'paid', label: 'مصروفة' },
                                ]}
                            />
                        </FilterModal>
                        <Button asChild variant="outline" disabled={totals.lineCount === 0}>
                            <a href={exportUrl}>
                                <Download className="size-4" /> تصدير Excel
                            </a>
                        </Button>
                    </div>
                </div>

                <ActiveFilterChips chips={chips} />

                {/* Summary cards */}
                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <SummaryCard icon={<TrendingUp className="size-4" />} label="إجمالي العمولات" value={formatCurrency(totals.earned)} />
                    <SummaryCard
                        icon={<Banknote className="size-4" />}
                        label="المصروف"
                        value={formatCurrency(totals.paid)}
                        valueClass="text-green-600"
                    />
                    <SummaryCard
                        icon={<Wallet className="size-4" />}
                        label="المستحق"
                        value={formatCurrency(totals.pending)}
                        valueClass="text-amber-600"
                    />
                    <SummaryCard
                        icon={<Wallet className="size-4" />}
                        label="منها تحضير"
                        value={formatCurrency(totals.tahazir)}
                        valueClass="text-muted-foreground"
                    />
                </div>

                {/* Summary table with drill-down */}
                <Card>
                    <CardHeader>
                        <CardTitle>العمولات حسب الموظف</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={summaryColumns}
                            data={summary}
                            keyExtractor={(row) => row.userId}
                            emptyState={<span className="text-muted-foreground">لا توجد عمولات مطابقة للتصفية</span>}
                            renderSubRow={(row) => <DetailLines lines={linesByUser.get(row.userId) ?? []} />}
                            footer={
                                <TableRow>
                                    <TableCell />
                                    <TableCell className="font-bold">الإجمالي</TableCell>
                                    <TableCell className="font-bold">{totals.lineCount}</TableCell>
                                    <TableCell className="font-bold">{formatCurrency(totals.earned)}</TableCell>
                                    <TableCell className="font-bold">{formatCurrency(totals.tahazir)}</TableCell>
                                    <TableCell className="font-bold text-green-600">{formatCurrency(totals.paid)}</TableCell>
                                    <TableCell className="font-bold text-amber-600">{formatCurrency(totals.pending)}</TableCell>
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

function InvoiceStatusBadge({ status }: { status: CommissionReportLine['invoiceStatus'] }) {
    if (status === 'cancelled') {
        return <Badge variant="destructive">ملغاة</Badge>;
    }
    if (status === 'due') {
        return (
            <Badge variant="outline" className="text-amber-600">
                غير مسددة
            </Badge>
        );
    }
    return <Badge className="bg-green-600">معتمدة</Badge>;
}

function DetailLines({ lines }: { lines: CommissionReportLine[] }) {
    return (
        <DataTable
            className="rounded-none border-0 bg-transparent shadow-none"
            columns={detailColumns}
            data={lines}
            keyExtractor={(line) => line.id}
            emptyState={<span className="text-muted-foreground p-4 text-sm">لا توجد بنود.</span>}
        />
    );
}
