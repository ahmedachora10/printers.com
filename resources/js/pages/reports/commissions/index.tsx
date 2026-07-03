import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
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
import { Banknote, ChevronDown, ChevronLeft, Download, TrendingUp, Wallet } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';

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

export default function CommissionReportIndex({ summary, lines, totals, filters, employees, branches, isSuperAdmin }: Props) {
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [employee, setEmployee] = useState(filters.employee ?? 'all');
    const [branch, setBranch] = useState(filters.branch ?? 'all');
    const [status, setStatus] = useState(filters.status ?? 'all');
    const [expanded, setExpanded] = useState<number | null>(null);

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
                    <CardContent>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-8" />
                                        <TableHead>الموظف</TableHead>
                                        <TableHead>عدد البنود</TableHead>
                                        <TableHead>إجمالي العمولة</TableHead>
                                        <TableHead>منها تحضير</TableHead>
                                        <TableHead>المصروف</TableHead>
                                        <TableHead>المستحق</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {summary.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={7} className="py-8 text-center text-muted-foreground">
                                                لا توجد عمولات مطابقة للتصفية
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {summary.map((row) => {
                                        const isOpen = expanded === row.userId;
                                        const detail = linesByUser.get(row.userId) ?? [];

                                        return (
                                            <Fragment key={row.userId}>
                                                <TableRow
                                                    className="cursor-pointer"
                                                    onClick={() => setExpanded(isOpen ? null : row.userId)}
                                                >
                                                    <TableCell>
                                                        {isOpen ? (
                                                            <ChevronDown className="size-4 text-muted-foreground" />
                                                        ) : (
                                                            <ChevronLeft className="size-4 text-muted-foreground" />
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="font-medium">{row.userName}</TableCell>
                                                    <TableCell>{row.lineCount}</TableCell>
                                                    <TableCell>{formatCurrency(row.earned)}</TableCell>
                                                    <TableCell className="text-muted-foreground">{formatCurrency(row.tahazir)}</TableCell>
                                                    <TableCell className="text-green-600">{formatCurrency(row.paid)}</TableCell>
                                                    <TableCell>
                                                        {row.pending > 0 ? (
                                                            <span className="font-semibold text-amber-600">{formatCurrency(row.pending)}</span>
                                                        ) : (
                                                            <span className="text-muted-foreground">—</span>
                                                        )}
                                                    </TableCell>
                                                </TableRow>
                                                {isOpen && (
                                                    <TableRow key={`${row.userId}-detail`} className="bg-muted/30 hover:bg-muted/30">
                                                        <TableCell colSpan={7} className="p-0">
                                                            <DetailLines lines={detail} />
                                                        </TableCell>
                                                    </TableRow>
                                                )}
                                            </Fragment>
                                        );
                                    })}
                                </TableBody>
                                {summary.length > 0 && (
                                    <TableFooter>
                                        <TableRow>
                                            <TableCell />
                                            <TableCell className="font-bold">الإجمالي</TableCell>
                                            <TableCell className="font-bold">{totals.lineCount}</TableCell>
                                            <TableCell className="font-bold">{formatCurrency(totals.earned)}</TableCell>
                                            <TableCell className="font-bold">{formatCurrency(totals.tahazir)}</TableCell>
                                            <TableCell className="font-bold text-green-600">{formatCurrency(totals.paid)}</TableCell>
                                            <TableCell className="font-bold text-amber-600">{formatCurrency(totals.pending)}</TableCell>
                                        </TableRow>
                                    </TableFooter>
                                )}
                            </Table>
                        </div>
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

function DetailLines({ lines }: { lines: CommissionReportLine[] }) {
    if (lines.length === 0) {
        return <p className="p-4 text-sm text-muted-foreground">لا توجد بنود.</p>;
    }

    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>رقم الفاتورة</TableHead>
                    <TableHead>الخدمة</TableHead>
                    <TableHead>المصدر</TableHead>
                    <TableHead>الشريحة</TableHead>
                    <TableHead>المبلغ</TableHead>
                    <TableHead>الحالة</TableHead>
                    <TableHead>تاريخ الاستحقاق</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {lines.map((line) => (
                    <TableRow key={line.id}>
                        <TableCell className="font-mono text-xs" dir="ltr">
                            {line.invoiceNumber}
                        </TableCell>
                        <TableCell>
                            {line.serviceName}
                            {line.isTahazir && (
                                <Badge variant="secondary" className="ms-2">
                                    تحضير
                                </Badge>
                            )}
                        </TableCell>
                        <TableCell className="text-sm text-muted-foreground">{line.sourceLabel}</TableCell>
                        <TableCell>{line.tierApplied ?? '—'}</TableCell>
                        <TableCell>{formatCurrency(line.amount)}</TableCell>
                        <TableCell>
                            {line.paidAt ? (
                                <Badge className="bg-green-600">مصروفة</Badge>
                            ) : (
                                <Badge variant="outline" className="text-amber-600">
                                    معلقة
                                </Badge>
                            )}
                        </TableCell>
                        <TableCell className="text-sm">{line.earnedAt ? formatDate(line.earnedAt) : '—'}</TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}
