import { DataTable, type ColumnDef } from '@/components/data-table';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import DateRangeBar from '@/components/reports/date-range-bar';
import { FilterSelect } from '@/components/reports/filter-fields';
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
    type AgentCommissionFilters,
    type AgentCommissionLine,
    type AgentCommissionRow,
    type AgentCommissionTotals,
} from '@/types/agent-commission-report';
import { Head } from '@inertiajs/react';
import { Banknote, Download, Handshake, Receipt, Wallet } from 'lucide-react';
import { useMemo } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'عمولات المناديب', href: '/reports/agent-commissions' }];

const REPORT_URL = '/reports/agent-commissions';

const EMPTY_STATE = <span className="text-muted-foreground">لا توجد عمولات مناديب ضمن الفترة المحددة</span>;

const agentColumns: ColumnDef<AgentCommissionRow>[] = [
    { key: 'agentName', header: 'المندوب', className: 'font-medium', cell: (row) => row.agentName },
    { key: 'invoiceCount', header: 'عدد الفواتير', cell: (row) => row.invoiceCount },
    { key: 'sales', header: 'إجمالي المبيعات', cell: (row) => formatCurrency(row.sales) },
    { key: 'discount', header: 'الخصم', className: 'text-amber-600', cell: (row) => formatCurrency(row.discount) },
    { key: 'rebate', header: 'الريبيت', cell: (row) => formatCurrency(row.rebate) },
    { key: 'lineCommission', header: 'عمولات البنود', className: 'text-sky-600', cell: (row) => formatCurrency(row.lineCommission) },
    { key: 'due', header: 'الإجمالي المستحق', className: 'font-semibold', cell: (row) => formatCurrency(row.due) },
    { key: 'paid', header: 'المدفوع', className: 'text-green-600', cell: (row) => formatCurrency(row.paid) },
    {
        key: 'outstanding',
        header: 'المتبقي',
        cell: (row) =>
            row.outstanding > 0 ? (
                <span className="font-semibold text-amber-600">{formatCurrency(row.outstanding)}</span>
            ) : (
                <span className="text-muted-foreground">—</span>
            ),
    },
];

const detailColumns: ColumnDef<AgentCommissionLine>[] = [
    {
        key: 'invoiceNumber',
        header: 'رقم الفاتورة',
        className: 'font-mono text-xs',
        cell: (line) => <span dir="ltr">{line.invoiceNumber ?? '—'}</span>,
    },
    { key: 'date', header: 'التاريخ', className: 'text-sm', cell: (line) => (line.date ? formatDate(line.date) : '—') },
    { key: 'employeeName', header: 'الموظف', cell: (line) => line.employeeName ?? '—' },
    { key: 'itemsLabel', header: 'الخدمة', className: 'text-muted-foreground', cell: (line) => line.itemsLabel ?? '—' },
    { key: 'invoiceTotal', header: 'إجمالي الفاتورة', cell: (line) => formatCurrency(line.invoiceTotal) },
    { key: 'amount', header: 'المستحق للمندوب', className: 'font-semibold', cell: (line) => formatCurrency(line.amount) },
    {
        key: 'isPaid',
        header: 'الحالة',
        cell: (line) =>
            line.isPaid ? (
                <Badge variant="secondary">مدفوعة</Badge>
            ) : (
                <Badge variant="outline" className="text-muted-foreground">
                    معلقة
                </Badge>
            ),
    },
];

interface Props {
    rows: AgentCommissionRow[];
    lines: AgentCommissionLine[];
    totals: AgentCommissionTotals;
    filters: AgentCommissionFilters;
    /** Today — the range the report opens on. */
    defaultDate: string;
    agents: { id: number; name: string }[];
    branches: { id: number; name: string }[];
    isSuperAdmin: boolean;
}

export default function AgentCommissionReportIndex({ rows, lines, totals, filters, defaultDate, agents, branches, isSuperAdmin }: Props) {
    const canPickBranch = isSuperAdmin && branches.length > 0;
    const canPickAgent = agents.length > 0;

    const defaults = useMemo<FilterValues>(() => ({ from: defaultDate, to: defaultDate, agent: 'all', branch: 'all' }), [defaultDate]);

    const applied: FilterValues = {
        from: filters.from,
        to: filters.to,
        agent: filters.agent ?? 'all',
        branch: filters.branch ?? 'all',
    };
    const f = useReportFilters(REPORT_URL, applied, defaults);

    const qs = new URLSearchParams(f.appliedQuery).toString();
    const exportUrl = `${REPORT_URL}/export${qs ? `?${qs}` : ''}`;

    // No chips for from/to — the range is always visible in the bar above.
    const chips: FilterChip[] = [];
    if (f.isActive('branch')) {
        const name = branches.find((b) => b.id.toString() === applied.branch)?.name ?? applied.branch;
        chips.push({ key: 'branch', label: `الفرع: ${name}`, onRemove: () => f.remove('branch') });
    }
    if (f.isActive('agent')) {
        const name = agents.find((a) => a.id.toString() === applied.agent)?.name ?? applied.agent;
        chips.push({ key: 'agent', label: `المندوب: ${name}`, onRemove: () => f.remove('agent') });
    }

    const linesByAgent = useMemo(() => {
        const map = new Map<number, AgentCommissionLine[]>();
        for (const line of lines) {
            const list = map.get(line.agentId) ?? [];
            list.push(line);
            map.set(line.agentId, list);
        }
        return map;
    }, [lines]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="عمولات المناديب" />
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">عمولات المناديب</h1>
                    <div className="flex items-center gap-2">
                        {(canPickBranch || canPickAgent) && (
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
                                {canPickAgent && (
                                    <FilterSelect
                                        label="المندوب"
                                        value={f.draft.agent}
                                        onChange={(v) => f.setField('agent', v)}
                                        allLabel="كل المناديب"
                                        options={agents.map((a) => ({ value: a.id.toString(), label: a.name }))}
                                    />
                                )}
                            </FilterModal>
                        )}
                        <Button asChild variant="outline" disabled={totals.agentCount === 0}>
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

                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <SummaryCard icon={<Receipt className="size-4" />} label="عدد الفواتير" value={totals.invoiceCount.toLocaleString('ar')} />
                    <SummaryCard icon={<Handshake className="size-4" />} label="الإجمالي المستحق" value={formatCurrency(totals.due)} />
                    <SummaryCard
                        icon={<Banknote className="size-4" />}
                        label="المدفوع"
                        value={formatCurrency(totals.paid)}
                        valueClass="text-green-600"
                    />
                    <SummaryCard
                        icon={<Wallet className="size-4" />}
                        label="المتبقي"
                        value={formatCurrency(totals.outstanding)}
                        valueClass="text-amber-600"
                    />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>العمولات حسب المندوب</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={agentColumns}
                            data={rows}
                            keyExtractor={(row) => row.agentId}
                            emptyState={EMPTY_STATE}
                            renderSubRow={(row) => <DetailLines lines={linesByAgent.get(row.agentId) ?? []} />}
                            footer={
                                <TableRow>
                                    <TableCell />
                                    <TableCell className="font-bold">الإجمالي</TableCell>
                                    <TableCell className="font-bold">{totals.invoiceCount}</TableCell>
                                    <TableCell className="font-bold">{formatCurrency(totals.sales)}</TableCell>
                                    <TableCell className="font-bold text-amber-600">{formatCurrency(totals.discount)}</TableCell>
                                    <TableCell className="font-bold">{formatCurrency(totals.rebate)}</TableCell>
                                    <TableCell className="font-bold text-sky-600">{formatCurrency(totals.lineCommission)}</TableCell>
                                    <TableCell className="font-bold">{formatCurrency(totals.due)}</TableCell>
                                    <TableCell className="font-bold text-green-600">{formatCurrency(totals.paid)}</TableCell>
                                    <TableCell className="font-bold text-amber-600">{formatCurrency(totals.outstanding)}</TableCell>
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

function DetailLines({ lines }: { lines: AgentCommissionLine[] }) {
    return (
        <DataTable
            className="rounded-none border-0 bg-transparent shadow-none"
            columns={detailColumns}
            data={lines}
            keyExtractor={(line) => `${line.type}-${line.invoiceNumber}`}
            emptyState={<span className="text-muted-foreground p-4 text-sm">لا توجد فواتير.</span>}
        />
    );
}
