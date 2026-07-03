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
    type SalesReportBranchRow,
    type SalesReportDayRow,
    type SalesReportEmployeeRow,
    type SalesReportFilters,
    type SalesReportPaymentMethodRow,
    type SalesReportTotals,
    type SalesReportTypeRow,
} from '@/types/sales-report';
import { Head, router } from '@inertiajs/react';
import { CreditCard, Download, Percent, Receipt, TrendingUp, Wallet } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'تقرير المبيعات', href: '/reports/sales' }];

const REPORT_URL = '/reports/sales';

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

export default function SalesReportIndex({
    totals,
    byType,
    byDay,
    byEmployee,
    byPaymentMethod,
    byBranch,
    filters,
    branches,
    isSuperAdmin,
}: Props) {
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [branch, setBranch] = useState(filters.branch ?? 'all');
    const [type, setType] = useState<SalesReportFilters['type']>(filters.type ?? 'all');

    const canPickBranch = isSuperAdmin && branches.length > 0;

    const query = useMemo(() => {
        const params: Record<string, string> = {};
        if (from) params.from = from;
        if (to) params.to = to;
        if (canPickBranch && branch !== 'all') params.branch = branch;
        if (type !== 'all') params.type = type;
        return params;
    }, [from, to, branch, type, canPickBranch]);

    function applyFilters() {
        router.get(REPORT_URL, query, { preserveState: true, preserveScroll: true, replace: true });
    }

    function resetFilters() {
        setFrom('');
        setTo('');
        setBranch('all');
        setType('all');
        router.get(REPORT_URL, {}, { preserveScroll: true, replace: true });
    }

    const exportUrl = useMemo(() => {
        const qs = new URLSearchParams(query).toString();
        return `${REPORT_URL}/export${qs ? `?${qs}` : ''}`;
    }, [query]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="تقرير المبيعات" />
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">تقرير المبيعات</h1>
                    <Button asChild variant="outline" disabled={totals.invoiceCount === 0}>
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
                            <Label>النوع</Label>
                            <Select value={type} onValueChange={(v) => setType(v as SalesReportFilters['type'])}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">الكل</SelectItem>
                                    <SelectItem value="product">منتجات</SelectItem>
                                    <SelectItem value="service">خدمات</SelectItem>
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
                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <SummaryCard icon={<Receipt className="size-4" />} label="عدد الفواتير" value={totals.invoiceCount.toLocaleString('ar')} />
                    <SummaryCard icon={<TrendingUp className="size-4" />} label="قبل الخصم" value={formatCurrency(totals.subtotal)} />
                    <SummaryCard icon={<Percent className="size-4" />} label="الخصومات" value={formatCurrency(totals.discounts)} valueClass="text-amber-600" />
                    <SummaryCard icon={<CreditCard className="size-4" />} label="الضريبة" value={formatCurrency(totals.vat)} valueClass="text-muted-foreground" />
                    <SummaryCard icon={<Wallet className="size-4" />} label="صافي المبيعات" value={formatCurrency(totals.total)} valueClass="text-green-600" />
                </div>

                {/* By type */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>المبيعات حسب النوع</CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>النوع</TableHead>
                                    <TableHead>عدد الفواتير</TableHead>
                                    <TableHead>قبل الخصم</TableHead>
                                    <TableHead>الخصومات</TableHead>
                                    <TableHead>الضريبة</TableHead>
                                    <TableHead>الإجمالي</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {byType.length === 0 && <EmptyRow span={6} />}
                                {byType.map((row) => (
                                    <TableRow key={row.type}>
                                        <TableCell className="font-medium">{row.label}</TableCell>
                                        <TableCell>{row.count}</TableCell>
                                        <TableCell>{formatCurrency(row.subtotal)}</TableCell>
                                        <TableCell className="text-amber-600">{formatCurrency(row.discounts)}</TableCell>
                                        <TableCell className="text-muted-foreground">{formatCurrency(row.vat)}</TableCell>
                                        <TableCell className="font-semibold text-green-600">{formatCurrency(row.total)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                            {byType.length > 0 && (
                                <TableFooter>
                                    <TableRow>
                                        <TableCell className="font-bold">الإجمالي</TableCell>
                                        <TableCell className="font-bold">{totals.invoiceCount}</TableCell>
                                        <TableCell className="font-bold">{formatCurrency(totals.subtotal)}</TableCell>
                                        <TableCell className="font-bold text-amber-600">{formatCurrency(totals.discounts)}</TableCell>
                                        <TableCell className="font-bold text-muted-foreground">{formatCurrency(totals.vat)}</TableCell>
                                        <TableCell className="font-bold text-green-600">{formatCurrency(totals.total)}</TableCell>
                                    </TableRow>
                                </TableFooter>
                            )}
                        </Table>
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
                    <CardContent className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>التاريخ</TableHead>
                                    <TableHead>عدد الفواتير</TableHead>
                                    <TableHead>الإجمالي</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {byDay.length === 0 && <EmptyRow span={3} />}
                                {byDay.map((row) => (
                                    <TableRow key={row.date}>
                                        <TableCell>{formatDate(row.date)}</TableCell>
                                        <TableCell>{row.count}</TableCell>
                                        <TableCell className="font-medium">{formatCurrency(row.total)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
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
            <CardContent className="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{nameHeader}</TableHead>
                            <TableHead>عدد الفواتير</TableHead>
                            <TableHead>الإجمالي</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 && <EmptyRow span={3} />}
                        {rows.map((row) => (
                            <TableRow key={row.key}>
                                <TableCell className="font-medium">{row.name}</TableCell>
                                <TableCell>{row.count}</TableCell>
                                <TableCell className="font-medium">{formatCurrency(row.total)}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                    {rows.length > 0 && (
                        <TableFooter>
                            <TableRow>
                                <TableCell className="font-bold">الإجمالي</TableCell>
                                <TableCell />
                                <TableCell className="font-bold text-green-600">{formatCurrency(total)}</TableCell>
                            </TableRow>
                        </TableFooter>
                    )}
                </Table>
            </CardContent>
        </Card>
    );
}

function EmptyRow({ span }: { span: number }) {
    return (
        <TableRow>
            <TableCell colSpan={span} className="py-8 text-center text-muted-foreground">
                لا توجد بيانات مطابقة للتصفية
            </TableCell>
        </TableRow>
    );
}
