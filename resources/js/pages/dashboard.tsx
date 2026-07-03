import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import {
    type DashboardKpis,
    type DashboardRecentInvoice,
    type DashboardScope,
    type DashboardTopService,
} from '@/types/dashboard';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CalendarDays, Package, Wallet } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'لوحة التحكم', href: '/dashboard' }];

interface Props {
    kpis: DashboardKpis;
    recentInvoices: DashboardRecentInvoice[];
    topServices: DashboardTopService[];
    scope: DashboardScope;
}

const STATUS: Record<DashboardRecentInvoice['status'], { label: string; className: string }> = {
    paid: { label: 'مدفوعة', className: 'bg-green-600' },
    due: { label: 'آجلة', className: 'bg-amber-500' },
    cancelled: { label: 'ملغاة', className: 'bg-muted text-muted-foreground' },
};

export default function Dashboard({ kpis, recentInvoices, topServices, scope }: Props) {
    const salesLabel = scope.isEmployee ? 'مبيعاتي' : 'المبيعات';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="لوحة التحكم" />
            <div className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-bold">مرحبًا، {scope.userName}</h1>
                    <p className="text-sm text-muted-foreground">نظرة عامة على الأداء</p>
                </div>

                {/* KPI tiles */}
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <KpiCard icon={<CalendarDays className="size-4" />} label={`${salesLabel} اليوم`} value={formatCurrency(kpis.todaySales)} />
                    <KpiCard icon={<CalendarDays className="size-4" />} label={`${salesLabel} هذا الشهر`} value={formatCurrency(kpis.monthSales)} valueClass="text-green-600" />
                    <KpiCard icon={<Wallet className="size-4" />} label="مستحقات آجلة" value={formatCurrency(kpis.outstandingDue)} valueClass="text-amber-600" />
                    <KpiCard icon={<Wallet className="size-4" />} label="عمولات معلقة" value={formatCurrency(kpis.pendingCommissions)} valueClass="text-amber-600" />
                    {kpis.lowStockCount !== null && (
                        <KpiCard
                            icon={kpis.lowStockCount > 0 ? <AlertTriangle className="size-4 text-amber-600" /> : <Package className="size-4" />}
                            label="منتجات منخفضة المخزون"
                            value={kpis.lowStockCount.toLocaleString('ar')}
                            valueClass={kpis.lowStockCount > 0 ? 'text-amber-600' : ''}
                        />
                    )}
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Recent invoices */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>أحدث الفواتير</CardTitle>
                        </CardHeader>
                        <CardContent className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>رقم الفاتورة</TableHead>
                                        <TableHead>العميل</TableHead>
                                        <TableHead>الإجمالي</TableHead>
                                        <TableHead>الحالة</TableHead>
                                        <TableHead>التاريخ</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {recentInvoices.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={5} className="py-8 text-center text-muted-foreground">
                                                لا توجد فواتير بعد
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {recentInvoices.map((inv) => (
                                        <TableRow key={`${inv.type}-${inv.id}`}>
                                            <TableCell>
                                                <Link
                                                    href={`/invoices/${inv.type}/${inv.id}`}
                                                    className="font-mono text-xs text-primary hover:underline"
                                                    dir="ltr"
                                                >
                                                    {inv.invoiceNumber}
                                                </Link>
                                            </TableCell>
                                            <TableCell>{inv.customerName ?? <span className="text-muted-foreground">—</span>}</TableCell>
                                            <TableCell className="font-medium">{formatCurrency(inv.total)}</TableCell>
                                            <TableCell>
                                                <Badge className={STATUS[inv.status].className}>{STATUS[inv.status].label}</Badge>
                                            </TableCell>
                                            <TableCell className="text-sm">{inv.createdAt ? formatDate(inv.createdAt) : '—'}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    {/* Top services */}
                    <Card>
                        <CardHeader>
                            <CardTitle>أعلى الخدمات هذا الشهر</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {topServices.length === 0 ? (
                                <p className="py-8 text-center text-sm text-muted-foreground">لا توجد بيانات</p>
                            ) : (
                                <ul className="space-y-3">
                                    {topServices.map((svc, i) => (
                                        <li key={svc.name} className="flex items-center justify-between gap-2">
                                            <span className="flex items-center gap-2">
                                                <span className="flex size-6 items-center justify-center rounded-full bg-muted text-xs font-semibold">
                                                    {i + 1}
                                                </span>
                                                <span className="text-sm">{svc.name}</span>
                                            </span>
                                            <span className="text-sm font-medium">{formatCurrency(svc.total)}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}

function KpiCard({ icon, label, value, valueClass }: { icon: React.ReactNode; label: string; value: string; valueClass?: string }) {
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
