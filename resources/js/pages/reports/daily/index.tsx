import { DataTable, type ColumnDef } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type DailyReportFilters, type DailyReportRow, type DailyReportTotals } from '@/types/daily-report';
import { Head, router } from '@inertiajs/react';
import { CalendarDays, CreditCard, Download, ShoppingCart, TrendingUp, Wallet } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'التقرير اليومي', href: '/reports/daily' }];

const REPORT_URL = '/reports/daily';

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
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [branch, setBranch] = useState(filters.branch ?? 'all');
    const [employee, setEmployee] = useState(filters.employee ?? 'all');

    const canPickBranch = isSuperAdmin && branches.length > 0;

    const query = useMemo(() => {
        const params: Record<string, string> = {};
        if (from) params.from = from;
        if (to) params.to = to;
        if (canPickBranch && branch !== 'all') params.branch = branch;
        if (employee !== 'all') params.employee = employee;
        return params;
    }, [from, to, branch, employee, canPickBranch]);

    function applyFilters() {
        router.get(REPORT_URL, query, { preserveState: true, preserveScroll: true, replace: true });
    }

    function resetFilters() {
        setFrom('');
        setTo('');
        setBranch('all');
        setEmployee('all');
        router.get(REPORT_URL, {}, { preserveScroll: true, replace: true });
    }

    const exportUrl = useMemo(() => {
        const qs = new URLSearchParams(query).toString();
        return `${REPORT_URL}/export${qs ? `?${qs}` : ''}`;
    }, [query]);

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
                    <Button asChild variant="outline" disabled={totals.dayCount === 0}>
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
                        <div className="flex items-end gap-2 sm:col-span-2 lg:col-span-5">
                            <Button onClick={applyFilters}>تطبيق</Button>
                            <Button variant="ghost" onClick={resetFilters}>
                                إعادة تعيين
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                {/* Summary tiles */}
                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <SummaryCard icon={<TrendingUp className="size-4" />} label="إجمالي المبيعات" value={formatCurrency(totals.total)} valueClass="text-green-600" />
                    <SummaryCard icon={<Wallet className="size-4" />} label="عمولة الموظفين" value={formatCurrency(totals.commission)} valueClass="text-amber-600" />
                    {showPurchases && (
                        <SummaryCard icon={<ShoppingCart className="size-4" />} label="المشتريات" value={formatCurrency(totals.purchases)} valueClass="text-rose-600" />
                    )}
                    {showPurchases && (
                        <SummaryCard icon={<CalendarDays className="size-4" />} label="المبلغ المتبقي" value={formatCurrency(totals.remaining)} />
                    )}
                    <SummaryCard icon={<CreditCard className="size-4" />} label="الضريبة" value={formatCurrency(totals.vat)} valueClass="text-muted-foreground" />
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
                                    <TableCell className="font-bold text-muted-foreground">{formatCurrency(totals.vat)}</TableCell>
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
