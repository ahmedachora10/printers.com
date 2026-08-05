import { ChartCard, PaymentMethodsChart, RevenueTrendChart, SalesByTypeChart, TopServicesChart } from '@/components/dashboard/charts';
import { DataTable, type ColumnDef } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import {
    type DashboardIncentive,
    type DashboardKpis,
    type DashboardPaymentMethod,
    type DashboardRecentInvoice,
    type DashboardSalesByType,
    type DashboardScope,
    type DashboardTopService,
    type DashboardTrendPoint,
} from '@/types/dashboard';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CalendarDays, Package, Target, Wallet } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'لوحة التحكم', href: '/dashboard' }];

interface Props {
    kpis: DashboardKpis;
    revenueTrend: DashboardTrendPoint[];
    salesByType: DashboardSalesByType | null;
    paymentMethods: DashboardPaymentMethod[] | null;
    topServices: DashboardTopService[];
    recentInvoices: DashboardRecentInvoice[];
    incentive: DashboardIncentive | null;
    scope: DashboardScope;
}

const STATUS: Record<DashboardRecentInvoice['status'], { label: string; className: string }> = {
    paid: { label: 'مدفوعة', className: 'bg-green-600' },
    due: { label: 'آجلة', className: 'bg-amber-500' },
    cancelled: { label: 'ملغاة', className: 'bg-muted text-muted-foreground' },
    returned: { label: 'مرتجع', className: 'bg-red-600' },
};

const recentInvoiceColumns: ColumnDef<DashboardRecentInvoice>[] = [
    {
        key: 'invoiceNumber',
        header: 'رقم الفاتورة',
        cell: (inv) => (
            <Link href={`/invoices/${inv.type}/${inv.id}`} className="font-mono text-xs text-primary hover:underline" dir="ltr">
                {inv.invoiceNumber}
            </Link>
        ),
    },
    { key: 'customerName', header: 'العميل', cell: (inv) => inv.customerName ?? <span className="text-muted-foreground">—</span> },
    { key: 'total', header: 'الإجمالي', className: 'font-medium', cell: (inv) => formatCurrency(inv.total) },
    { key: 'status', header: 'الحالة', cell: (inv) => <Badge className={STATUS[inv.status].className}>{STATUS[inv.status].label}</Badge> },
    { key: 'createdAt', header: 'التاريخ', className: 'text-sm', cell: (inv) => (inv.createdAt ? formatDate(inv.createdAt) : '—') },
];

export default function Dashboard({ kpis, revenueTrend, salesByType, paymentMethods, topServices, recentInvoices, incentive, scope }: Props) {
    const salesLabel = scope.isEmployee ? 'مبيعاتي' : 'المبيعات';
    const trendTitle = `${scope.isEmployee ? 'مبيعاتي' : 'المبيعات'} خلال آخر ${scope.trendDays} يومًا`;

    const tiles: { label: string; value: string; icon: React.ReactNode; valueClass?: string }[] = [];
    if (kpis.todaySales !== null)
        tiles.push({ label: `${salesLabel} اليوم`, value: formatCurrency(kpis.todaySales), icon: <CalendarDays className="size-4" /> });
    if (kpis.monthSales !== null)
        tiles.push({ label: `${salesLabel} هذا الشهر`, value: formatCurrency(kpis.monthSales), icon: <CalendarDays className="size-4" />, valueClass: 'text-green-600' });
    if (kpis.outstandingDue !== null)
        tiles.push({ label: 'مستحقات آجلة', value: formatCurrency(kpis.outstandingDue), icon: <Wallet className="size-4" />, valueClass: 'text-amber-600' });
    if (kpis.pendingCommissions !== null)
        tiles.push({
            label: scope.isEmployee ? 'عمولاتي المعلقة' : 'عمولات معلقة',
            value: formatCurrency(kpis.pendingCommissions),
            icon: <Wallet className="size-4" />,
            valueClass: 'text-amber-600',
        });
    if (kpis.lowStockCount !== null)
        tiles.push({
            label: 'منتجات منخفضة المخزون',
            value: kpis.lowStockCount.toLocaleString('ar'),
            icon: kpis.lowStockCount > 0 ? <AlertTriangle className="size-4 text-amber-600" /> : <Package className="size-4" />,
            valueClass: kpis.lowStockCount > 0 ? 'text-amber-600' : undefined,
        });

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
                    {tiles.map((t) => (
                        <KpiCard key={t.label} icon={t.icon} label={t.label} value={t.value} valueClass={t.valueClass} />
                    ))}
                </div>

                {/* Charts */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <ChartCard title={trendTitle} className="lg:col-span-2">
                        <RevenueTrendChart data={revenueTrend} singleSeries={scope.isEmployee} />
                    </ChartCard>

                    {salesByType ? (
                        <ChartCard title="المبيعات حسب النوع">
                            <SalesByTypeChart data={salesByType} />
                        </ChartCard>
                    ) : incentive ? (
                        <IncentiveCard incentive={incentive} />
                    ) : (
                        <ChartCard title="أعلى الخدمات">
                            <TopServicesChart data={topServices} />
                        </ChartCard>
                    )}

                    {paymentMethods && (
                        <ChartCard title="حسب طريقة الدفع">
                            <PaymentMethodsChart data={paymentMethods} />
                        </ChartCard>
                    )}

                    {/* Top services gets its own card when not already used as the fallback above. */}
                    {(salesByType || incentive) && (
                        <ChartCard title="أعلى الخدمات" className={paymentMethods ? '' : 'lg:col-span-2'}>
                            <TopServicesChart data={topServices} />
                        </ChartCard>
                    )}
                </div>

                {/* Recent invoices */}
                <Card>
                    <CardHeader>
                        <CardTitle>أحدث الفواتير</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={recentInvoiceColumns}
                            data={recentInvoices}
                            keyExtractor={(inv) => `${inv.type}-${inv.id}`}
                            emptyState={<span className="text-sm text-muted-foreground">لا توجد فواتير بعد</span>}
                        />
                    </CardContent>
                </Card>
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

function IncentiveCard({ incentive }: { incentive: DashboardIncentive }) {
    const met = incentive.pct >= 100;
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Target className="size-4" /> حافز هذا الشهر
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                <div>
                    <div className="mb-1 flex items-baseline justify-between">
                        <span className="text-sm text-muted-foreground">التقدّم نحو الهدف</span>
                        <span className={`text-sm font-semibold ${met ? 'text-green-600' : ''}`}>{incentive.pct}%</span>
                    </div>
                    <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                        <div className={`h-full rounded-full ${met ? 'bg-green-600' : 'bg-primary'}`} style={{ width: `${Math.min(100, incentive.pct)}%` }} />
                    </div>
                </div>
                <div className="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <p className="text-muted-foreground">المحقّق</p>
                        <p className="font-semibold">{formatCurrency(incentive.achieved)}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">الهدف</p>
                        <p className="font-semibold">{formatCurrency(incentive.target)}</p>
                    </div>
                    <div className="col-span-2">
                        <p className="text-muted-foreground">المكافأة عند التحقيق</p>
                        <p className="font-semibold text-green-600">{formatCurrency(incentive.bonus)}</p>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
