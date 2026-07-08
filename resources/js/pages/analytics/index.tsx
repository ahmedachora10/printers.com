import { ExportChartCard, PointsMonthlyChart, TierDistributionChart } from '@/components/analytics/charts';
import { RevenueTrendChart, SalesByTypeChart, TopServicesChart } from '@/components/dashboard/charts';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import {
    type AnalyticsFilters,
    type AnalyticsLoyalty,
    type AnalyticsRankedRow,
    type AnalyticsSalesByType,
    type AnalyticsTrendPoint,
} from '@/types/analytics';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'التحليلات المتقدمة', href: '/analytics' }];

const PAGE_URL = '/analytics';

interface Props {
    dailyRevenue: AnalyticsTrendPoint[];
    salesByType: AnalyticsSalesByType;
    topServices: AnalyticsRankedRow[];
    employeePerformance: AnalyticsRankedRow[];
    byBranch: AnalyticsRankedRow[];
    loyalty: AnalyticsLoyalty;
    filters: AnalyticsFilters;
    branches: { id: number; name: string }[];
    isSuperAdmin: boolean;
}

const TYPE_LEGEND = [
    { label: 'منتجات', color: '--viz-1' },
    { label: 'خدمات', color: '--viz-2' },
];

const POINTS_LEGEND = [
    { label: 'نقاط مكتسبة', color: '--viz-1' },
    { label: 'نقاط مستبدلة', color: '--viz-2' },
];

const TIER_LEGEND = [
    { label: 'بدون فئة', color: '--viz-1' },
    { label: 'برونزي', color: '--viz-2' },
    { label: 'فضي', color: '--viz-3' },
    { label: 'ذهبي', color: '--viz-4' },
];

export default function AnalyticsIndex({
    dailyRevenue,
    salesByType,
    topServices,
    employeePerformance,
    byBranch,
    loyalty,
    filters,
    branches,
    isSuperAdmin,
}: Props) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [branch, setBranch] = useState(filters.branch ?? 'all');

    const canPickBranch = isSuperAdmin && branches.length > 0;

    const query = useMemo(() => {
        const params: Record<string, string> = {};
        if (from) params.from = from;
        if (to) params.to = to;
        if (canPickBranch && branch !== 'all') params.branch = branch;
        return params;
    }, [from, to, branch, canPickBranch]);

    function applyFilters() {
        router.get(PAGE_URL, query, { preserveState: true, preserveScroll: true, replace: true });
    }

    function resetFilters() {
        setBranch('all');
        router.get(PAGE_URL, {}, { preserveScroll: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="التحليلات المتقدمة" />
            <div className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-2xl font-bold">التحليلات المتقدمة</h1>
                    <p className="text-sm text-muted-foreground">رسوم بيانية تفاعلية للمبيعات والولاء — يمكن تصدير كل رسم كصورة PNG</p>
                </div>

                {/* Date range + branch filters, applied to every chart */}
                <Card>
                    <CardContent className="grid grid-cols-1 gap-4 pt-6 sm:grid-cols-2 lg:grid-cols-4">
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
                        <div className="flex items-end gap-2">
                            <Button onClick={applyFilters}>تطبيق</Button>
                            <Button variant="ghost" onClick={resetFilters}>
                                إعادة تعيين
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <Tabs defaultValue="sales">
                    <TabsList>
                        <TabsTrigger value="sales">المبيعات</TabsTrigger>
                        <TabsTrigger value="loyalty">تحليلات الولاء</TabsTrigger>
                    </TabsList>

                    <TabsContent value="sales" className="mt-4">
                        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                            <ExportChartCard title="الإيراد اليومي" filename="daily-revenue.png" legend={TYPE_LEGEND} className="lg:col-span-2">
                                <RevenueTrendChart data={dailyRevenue} />
                            </ExportChartCard>

                            <ExportChartCard title="المبيعات حسب النوع" filename="sales-by-type.png" legend={TYPE_LEGEND}>
                                <SalesByTypeChart data={salesByType} />
                            </ExportChartCard>

                            <ExportChartCard
                                title="أعلى 10 خدمات"
                                filename="top-services.png"
                                className={isSuperAdmin ? 'lg:col-span-1' : 'lg:col-span-2'}
                            >
                                <TopServicesChart data={topServices} />
                            </ExportChartCard>

                            <ExportChartCard title="أداء الموظفين" filename="employee-performance.png">
                                <TopServicesChart data={employeePerformance} />
                            </ExportChartCard>

                            {isSuperAdmin && (
                                <ExportChartCard title="مقارنة الفروع" filename="branch-comparison.png">
                                    <TopServicesChart data={byBranch} />
                                </ExportChartCard>
                            )}
                        </div>
                    </TabsContent>

                    <TabsContent value="loyalty" className="mt-4">
                        <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                            <ExportChartCard title="توزيع فئات الولاء" filename="loyalty-tiers.png" legend={TIER_LEGEND}>
                                <TierDistributionChart data={loyalty.tierDistribution} />
                            </ExportChartCard>

                            <ExportChartCard
                                title="النقاط المكتسبة مقابل المستبدلة شهريًا"
                                filename="loyalty-points-monthly.png"
                                legend={POINTS_LEGEND}
                                className="lg:col-span-2"
                            >
                                <PointsMonthlyChart data={loyalty.pointsMonthly} />
                            </ExportChartCard>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
