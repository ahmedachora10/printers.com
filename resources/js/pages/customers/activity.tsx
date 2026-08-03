import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { ChartCard, compact, EmptyState, SLOT, VizTooltip } from '@/components/viz';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils';
import customersRoute from '@/routes/customers';
import invoicesRoute from '@/routes/invoices';
import { type BreadcrumbItem } from '@/types';
import {
    type Customer,
    type CustomerAnalytics,
    type CustomerTimelineEvent,
    type TimelineAuditEvent,
    type TimelineInvoiceEvent,
    type TimelineLoyaltyEvent,
    type TimelineRefundEvent,
} from '@/types/customer';
import { Deferred, Head, Link } from '@inertiajs/react';
import { ArrowRight, FileText, PencilLine, Star, Undo2, UserPlus } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

const STATUS_COLORS: Record<string, string> = {
    paid: 'border-green-200 bg-green-50 text-green-700',
    due: 'border-red-200 bg-red-50 text-red-700',
    cancelled: 'border-border bg-muted/60 text-muted-foreground',
};

const STATUS_LABELS: Record<string, string> = {
    paid: 'مدفوع',
    due: 'مستحق',
    cancelled: 'ملغي',
};

const LOYALTY_LABELS: Record<string, string> = {
    earn: 'نقاط مكتسبة',
    redeem: 'استبدال نقاط',
    manual_adjust: 'تعديل يدوي للنقاط',
    expire: 'انتهاء صلاحية نقاط',
};

/** Arabic labels for audited customer fields shown in "updated" timeline entries. */
const FIELD_LABELS: Record<string, string> = {
    full_name: 'الاسم',
    phone: 'الهاتف',
    email: 'البريد الإلكتروني',
    branch_id: 'الفرع',
    customer_type: 'نوع العميل',
    company_name: 'اسم الشركة',
    tax_number: 'الرقم الضريبي',
    credit_limit: 'الحد الائتماني',
    agent_id: 'المندوب',
    points_balance: 'رصيد النقاط',
    cumulative_spend: 'الإنفاق التراكمي',
    tier: 'فئة الولاء',
    notes: 'الملاحظات',
    is_active: 'الحالة',
};

interface Props {
    customer: Customer;
    /** Deferred prop — undefined until its follow-up request resolves. */
    timeline?: CustomerTimelineEvent[];
    /** Deferred prop — undefined until its follow-up request resolves. */
    analytics?: CustomerAnalytics;
}

/** Month tick: "2026-03" → "3/2026". */
function shortMonth(key: string): string {
    const [year, month] = key.split('-');
    return `${Number(month)}/${year}`;
}

function MonthlySpendChart({ data }: { data: CustomerAnalytics['monthlySpend'] }) {
    const hasData = data.some((d) => d.service > 0 || d.product > 0);
    if (!hasData) return <EmptyState />;

    return (
        <ResponsiveContainer width="100%" height={260}>
            <BarChart data={data} margin={{ top: 8, right: 12, left: 4, bottom: 0 }}>
                <CartesianGrid stroke="var(--viz-grid)" vertical={false} />
                <XAxis
                    dataKey="month"
                    tickFormatter={shortMonth}
                    tick={{ fill: 'var(--viz-muted)', fontSize: 11 }}
                    stroke="var(--viz-axis)"
                    interval="preserveStartEnd"
                    minTickGap={24}
                />
                <YAxis tickFormatter={compact} tick={{ fill: 'var(--viz-muted)', fontSize: 11 }} stroke="var(--viz-axis)" width={44} />
                <Tooltip cursor={{ fill: 'var(--viz-grid)', opacity: 0.4 }} content={<VizTooltip />} labelFormatter={(l) => shortMonth(String(l))} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
                <Bar dataKey="service" name="خدمات" stackId="spend" fill={SLOT(1)} stroke="var(--viz-surface)" strokeWidth={1} />
                <Bar
                    dataKey="product"
                    name="منتجات"
                    stackId="spend"
                    fill={SLOT(0)}
                    stroke="var(--viz-surface)"
                    strokeWidth={1}
                    radius={[3, 3, 0, 0]}
                />
            </BarChart>
        </ResponsiveContainer>
    );
}

function KpiTile({ label, value, hint }: { label: string; value: React.ReactNode; hint?: string }) {
    return (
        <div className="bg-muted/40 rounded-lg p-4">
            <p className="text-muted-foreground text-sm">{label}</p>
            <p className="mt-1 text-xl font-bold tabular-nums" dir="ltr">
                {value}
            </p>
            {hint && <p className="text-muted-foreground mt-0.5 text-xs">{hint}</p>}
        </div>
    );
}

function TopItemsCard({ title, items }: { title: string; items?: CustomerAnalytics['topServices'] }) {
    const max = Math.max(...(items?.map((i) => i.total) ?? []), 1);

    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
            </CardHeader>
            <CardContent>
                <Deferred data="analytics" fallback={<TopItemsSkeleton />}>
                    <>
                        {!items ? null : items.length === 0 ? (
                            <p className="text-muted-foreground py-6 text-center text-sm">لا توجد مشتريات</p>
                        ) : (
                            <div className="space-y-3">
                                {items.map((item) => (
                                    <div key={item.name}>
                                        <div className="mb-1 flex items-center justify-between gap-2 text-sm">
                                            <span className="truncate font-medium">{item.name}</span>
                                            <span className="text-muted-foreground shrink-0 tabular-nums" dir="ltr">
                                                {formatCurrency(item.total)}
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <div className="bg-muted h-1.5 flex-1 overflow-hidden rounded-full">
                                                <div
                                                    className="bg-primary/70 h-full rounded-full"
                                                    style={{ width: `${(item.total / max) * 100}%` }}
                                                />
                                            </div>
                                            <span className="text-muted-foreground shrink-0 text-xs tabular-nums">×{formatNumber(item.qty)}</span>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </>
                </Deferred>
            </CardContent>
        </Card>
    );
}

function AuditEntry({ event }: { event: TimelineAuditEvent }) {
    const isCreated = event.event === 'created';
    const fields = event.changedFields.map((f) => FIELD_LABELS[f] ?? f);

    return (
        <TimelineRow
            icon={isCreated ? <UserPlus className="size-4" /> : <PencilLine className="size-4" />}
            iconClass="bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-300"
            title={isCreated ? 'إنشاء ملف العميل' : 'تحديث بيانات العميل'}
            occurredAt={event.occurredAt}
        >
            {!isCreated && fields.length > 0 && <p className="text-muted-foreground text-xs">الحقول المعدّلة: {fields.join('، ')}</p>}
            {event.causer && <p className="text-muted-foreground text-xs">بواسطة {event.causer}</p>}
        </TimelineRow>
    );
}

function InvoiceEntry({ event }: { event: TimelineInvoiceEvent }) {
    return (
        <TimelineRow
            icon={<FileText className="size-4" />}
            iconClass="bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300"
            title={
                <span className="flex flex-wrap items-center gap-2">
                    <Link
                        href={invoicesRoute.show({ type: event.invoiceType, id: event.invoiceId }).url}
                        className="font-mono text-xs hover:underline"
                        dir="ltr"
                    >
                        {event.invoiceNumber}
                    </Link>
                    <Badge variant="secondary" className="text-xs">
                        {event.invoiceType === 'service' ? 'خدمة' : 'منتج'}
                    </Badge>
                    <Badge variant="outline" className={STATUS_COLORS[event.status]}>
                        {STATUS_LABELS[event.status]}
                    </Badge>
                </span>
            }
            occurredAt={event.occurredAt}
        >
            <p className="text-sm font-medium tabular-nums" dir="ltr">
                {formatCurrency(event.amount)}
            </p>
        </TimelineRow>
    );
}

function LoyaltyEntry({ event }: { event: TimelineLoyaltyEvent }) {
    return (
        <TimelineRow
            icon={<Star className="size-4" />}
            iconClass="bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300"
            title={LOYALTY_LABELS[event.loyaltyType] ?? event.loyaltyType}
            occurredAt={event.occurredAt}
        >
            <p className="text-sm">
                <span className={`font-bold tabular-nums ${event.points > 0 ? 'text-green-700' : 'text-destructive'}`}>
                    {event.points > 0 ? '+' : ''}
                    {formatNumber(event.points)}
                </span>{' '}
                <span className="text-muted-foreground text-xs">(الرصيد: {formatNumber(event.balanceAfter)})</span>
            </p>
            {event.notes && <p className="text-muted-foreground text-xs">{event.notes}</p>}
        </TimelineRow>
    );
}

function RefundEntry({ event }: { event: TimelineRefundEvent }) {
    return (
        <TimelineRow
            icon={<Undo2 className="size-4" />}
            iconClass="bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300"
            title="استرجاع"
            occurredAt={event.occurredAt}
        >
            <p className="text-destructive text-sm font-medium tabular-nums" dir="ltr">
                −{formatCurrency(event.amount)}
            </p>
            <p className="text-muted-foreground text-xs">{event.reason}</p>
        </TimelineRow>
    );
}

function TimelineRow({
    icon,
    iconClass,
    title,
    occurredAt,
    children,
}: {
    icon: React.ReactNode;
    iconClass: string;
    title: React.ReactNode;
    occurredAt: string;
    children?: React.ReactNode;
}) {
    return (
        <div className="relative flex gap-3 pb-6 last:pb-0">
            {/* Connector line */}
            <div className="bg-border absolute start-4 top-8 bottom-0 w-px -translate-x-1/2 rtl:translate-x-1/2" aria-hidden />
            <div className={`z-10 flex size-8 shrink-0 items-center justify-center rounded-full ${iconClass}`}>{icon}</div>
            <div className="min-w-0 flex-1 pt-1">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="text-sm font-medium">{title}</div>
                    <span className="text-muted-foreground shrink-0 text-xs">{formatDate(occurredAt)}</span>
                </div>
                {children}
            </div>
        </div>
    );
}

function KpiGrid({ kpis }: { kpis: CustomerAnalytics['kpis'] }) {
    return (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <KpiTile
                label="الإنفاق المحقق"
                value={formatCurrency(kpis.lifetimeSpend)}
                hint={`${formatNumber(kpis.paidInvoiceCount)} فاتورة مدفوعة`}
            />
            <KpiTile label="متوسط قيمة الفاتورة" value={formatCurrency(kpis.avgInvoiceValue)} />
            <KpiTile
                label="معدل الشراء الشهري"
                value={kpis.purchasesPerMonth}
                hint={kpis.firstPurchaseAt ? `عميل منذ ${formatDate(kpis.firstPurchaseAt)}` : undefined}
            />
            <KpiTile
                label="آخر عملية شراء"
                value={kpis.daysSinceLastPurchase !== null ? `${formatNumber(kpis.daysSinceLastPurchase)} يوم` : '—'}
                hint={kpis.lastPurchaseAt ? formatDate(kpis.lastPurchaseAt) : undefined}
            />
        </div>
    );
}

function KpiGridSkeleton() {
    return (
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
            {Array.from({ length: 4 }).map((_, i) => (
                <div key={i} className="bg-muted/40 rounded-lg p-4">
                    <Skeleton className="h-4 w-24" />
                    <Skeleton className="mt-2 h-6 w-32" />
                </div>
            ))}
        </div>
    );
}

function TimelineSkeleton() {
    return (
        <div className="space-y-6">
            {Array.from({ length: 5 }).map((_, i) => (
                <div key={i} className="flex gap-3">
                    <Skeleton className="size-8 shrink-0 rounded-full" />
                    <div className="flex-1 space-y-2 pt-1">
                        <Skeleton className="h-4 w-2/3" />
                        <Skeleton className="h-3 w-1/3" />
                    </div>
                </div>
            ))}
        </div>
    );
}

function TopItemsSkeleton() {
    return (
        <div className="space-y-4">
            {Array.from({ length: 3 }).map((_, i) => (
                <div key={i} className="space-y-2">
                    <Skeleton className="h-4 w-3/4" />
                    <Skeleton className="h-1.5 w-full rounded-full" />
                </div>
            ))}
        </div>
    );
}

export default function CustomerActivity({ customer, timeline, analytics }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'العملاء', href: customersRoute.index().url },
        { title: customer.fullName, href: customersRoute.show(customer.id).url },
        { title: 'النشاط والتحليلات', href: customersRoute.activity(customer.id).url },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`نشاط ${customer.fullName}`} />
            <div className="flex flex-col gap-6 p-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold">نشاط العميل وتحليلاته</h1>
                        <p className="text-muted-foreground text-sm">{customer.fullName}</p>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={customersRoute.show(customer.id).url}>
                            <ArrowRight className="size-4" />
                            الملف الشخصي
                        </Link>
                    </Button>
                </div>

                {/* Purchase KPIs (deferred with the analytics prop) */}
                <Deferred data="analytics" fallback={<KpiGridSkeleton />}>
                    <>{analytics ? <KpiGrid kpis={analytics.kpis} /> : null}</>
                </Deferred>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div className="space-y-6 lg:col-span-2">
                        <ChartCard title="الإنفاق الشهري (آخر 12 شهراً — فواتير مدفوعة)">
                            <Deferred data="analytics" fallback={<Skeleton className="h-[260px] w-full" />}>
                                <>{analytics ? <MonthlySpendChart data={analytics.monthlySpend} /> : null}</>
                            </Deferred>
                        </ChartCard>

                        {/* Unified timeline */}
                        <Card>
                            <CardHeader>
                                <CardTitle>سجل النشاط</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <Deferred data="timeline" fallback={<TimelineSkeleton />}>
                                    <>
                                        {!timeline ? null : timeline.length === 0 ? (
                                            <p className="text-muted-foreground py-8 text-center text-sm">لا يوجد نشاط مسجّل</p>
                                        ) : (
                                            <div>
                                                {timeline.map((event) => {
                                                    switch (event.kind) {
                                                        case 'audit':
                                                            return <AuditEntry key={event.id} event={event} />;
                                                        case 'invoice':
                                                            return <InvoiceEntry key={event.id} event={event} />;
                                                        case 'loyalty':
                                                            return <LoyaltyEntry key={event.id} event={event} />;
                                                        case 'refund':
                                                            return <RefundEntry key={event.id} event={event} />;
                                                    }
                                                })}
                                            </div>
                                        )}
                                    </>
                                </Deferred>
                            </CardContent>
                        </Card>
                    </div>

                    <div className="space-y-6">
                        <TopItemsCard title="أكثر الخدمات شراءً" items={analytics?.topServices} />
                        <TopItemsCard title="أكثر المنتجات شراءً" items={analytics?.topProducts} />
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
