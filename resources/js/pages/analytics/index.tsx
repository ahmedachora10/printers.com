import { ExportChartCard, PointsMonthlyChart, TierDistributionChart } from '@/components/analytics/charts';
import { RevenueTrendChart, SalesByTypeChart, TopServicesChart } from '@/components/dashboard/charts';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import DateRangeBar from '@/components/reports/date-range-bar';
import { FilterSelect } from '@/components/reports/filter-fields';
import { FilterModal } from '@/components/reports/filter-modal';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import {
    type AnalyticsFilters,
    type AnalyticsLoyalty,
    type AnalyticsRankedRow,
    type AnalyticsSalesByType,
    type AnalyticsTrendPoint,
} from '@/types/analytics';
import { Head } from '@inertiajs/react';

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
    const canPickBranch = isSuperAdmin && branches.length > 0;

    // The date range is always populated (server-defaulted), so only the branch
    // is an optional filter — it alone drives the active count and the chips.
    const applied: FilterValues = {
        from: filters.from,
        to: filters.to,
        branch: filters.branch ?? 'all',
    };
    const f = useReportFilters(PAGE_URL, applied, { from: '', to: '', branch: 'all' });

    const chips: FilterChip[] = [];
    if (f.isActive('branch')) {
        const name = branches.find((b) => b.id.toString() === applied.branch)?.name ?? applied.branch;
        chips.push({ key: 'branch', label: `الفرع: ${name}`, onRemove: () => f.remove('branch') });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="التحليلات المتقدمة" />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold">التحليلات المتقدمة</h1>
                        <p className="text-muted-foreground text-sm">رسوم بيانية تفاعلية للمبيعات والولاء — يمكن تصدير كل رسم كصورة PNG</p>
                    </div>
                    {/* The range lives in the bar below; the branch is the only
                        thing left to filter, so the modal is a super-admin affair. */}
                    {canPickBranch && (
                        <FilterModal
                            open={f.open}
                            onOpenChange={f.onOpenChange}
                            onApply={f.apply}
                            onReset={f.reset}
                            activeCount={f.isActive('branch') ? 1 : 0}
                        >
                            <FilterSelect
                                label="الفرع"
                                value={f.draft.branch}
                                onChange={(v) => f.setField('branch', v)}
                                allLabel="كل الفروع"
                                options={branches.map((b) => ({ value: b.id.toString(), label: b.name }))}
                            />
                        </FilterModal>
                    )}
                </div>

                <DateRangeBar filters={f} from={applied.from} to={applied.to} />

                <ActiveFilterChips chips={chips} />

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
