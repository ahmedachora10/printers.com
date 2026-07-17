import { DataTable, type ColumnDef } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import {
    type CommissionReportFilters,
    type CommissionReportLine,
    type CommissionReportSummaryRow,
    type CommissionReportTotals,
} from '@/types/report';
import { router } from '@inertiajs/react';
import { Banknote, Download, TrendingUp, Wallet } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'تقرير العمولات', href: '/reports/commissions' }];

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
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [employee, setEmployee] = useState(filters.employee ?? 'all');
    const [branch, setBranch] = useState(filters.branch ?? 'all');
    const [status, setStatus] = useState(filters.status ?? 'all');

    const canPickEmployee = employees.length > 0;
    const canPickBranch = isSuperAdmin && branches.length > 0;

    const query = useMemo(() => {
        const params: Record<string, string> = {};
        if (from) params.from = from;
        if (to) params.to = to;
        if (canPickEmployee && employee !== 'all') params.employee = employee;
        if (canPickBranch && branch !== 'all') params.branch = branch;
        if (status !== 'all') params.status = status;
        return params;
    }, [from, to, employee, branch, status, canPickEmployee, canPickBranch]);

    function applyFilters() {
        router.get(REPORT_URL, query, { preserveState: true, preserveScroll: true, replace: true });
    }

    function resetFilters() {
        setFrom('');
        setTo('');
        setEmployee('all');
        setBranch('all');
        setStatus('all');
        router.get(REPORT_URL, {}, { preserveScroll: true, replace: true });
    }

    const exportUrl = useMemo(() => {
        const qs = new URLSearchParams(query).toString();
        return `${REPORT_URL}/export${qs ? `?${qs}` : ''}`;
    }, [query]);

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
                    <Button asChild variant="outline" disabled={totals.lineCount === 0}>
                        <a href={exportUrl}>
                            <Download className="size-4" /> تصدير Excel
                        </a>
                    </Button>
                </div>

                {/* Filters */}
                <Card className="mb-6">
                    <CardContent className="grid grid-cols-1 gap-4 pt-6 sm:grid-cols-2 lg:grid-cols-5">
                        <div className="space-y-1.5">
                            <Label htmlFor="from">من تاريخ</Label>
                            <Input id="from" type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="to">إلى تاريخ</Label>
                            <Input id="to" type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                        </div>
                        {canPickBranch && (
                            <div className="space-y-1.5">
                                <Label>الفرع</Label>
                                <Select value={branch} onValueChange={setBranch}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="كل الفروع" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">كل الفروع</SelectItem>
                                        {branches.map((b) => (
                                            <SelectItem key={b.id} value={b.id.toString()}>
                                                {b.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                        {canPickEmployee && (
                            <div className="space-y-1.5">
                                <Label>الموظف</Label>
                                <Select value={employee} onValueChange={setEmployee}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="كل الموظفين" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">كل الموظفين</SelectItem>
                                        {employees.map((e) => (
                                            <SelectItem key={e.id} value={e.id.toString()}>
                                                {e.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        )}
                        <div className="space-y-1.5">
                            <Label>الحالة</Label>
                            <Select value={status} onValueChange={(v) => setStatus(v as CommissionReportFilters['status'])}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">الكل</SelectItem>
                                    <SelectItem value="pending">معلقة</SelectItem>
                                    <SelectItem value="paid">مصروفة</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="flex items-end gap-2 sm:col-span-2 lg:col-span-5">
                            <Button onClick={applyFilters}>تطبيق</Button>
                            <Button variant="ghost" onClick={resetFilters}>
                                إعادة تعيين
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Summary cards */}
                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <SummaryCard icon={<TrendingUp className="size-4" />} label="إجمالي العمولات" value={formatCurrency(totals.earned)} />
                    <SummaryCard icon={<Banknote className="size-4" />} label="المصروف" value={formatCurrency(totals.paid)} valueClass="text-green-600" />
                    <SummaryCard icon={<Wallet className="size-4" />} label="المستحق" value={formatCurrency(totals.pending)} valueClass="text-amber-600" />
                    <SummaryCard icon={<Wallet className="size-4" />} label="منها تحضير" value={formatCurrency(totals.tahazir)} valueClass="text-muted-foreground" />
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
                <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
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
            emptyState={<span className="p-4 text-sm text-muted-foreground">لا توجد بنود.</span>}
        />
    );
}
