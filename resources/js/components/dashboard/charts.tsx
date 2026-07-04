import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatCurrency } from '@/lib/utils';
import { type DashboardPaymentMethod, type DashboardSalesByType, type DashboardTopService, type DashboardTrendPoint } from '@/types/dashboard';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    LabelList,
    Legend,
    Line,
    LineChart,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

/**
 * Theme-aware chart palette. Values come from the validated data-viz reference
 * palette (categorical slots in fixed order, plus recessive chrome). Defined as
 * CSS custom properties so light/dark swap in one place and Recharts marks
 * reference them by role via var(--…). The app toggles a `.dark` class on
 * <html> (see use-appearance), so dark steps hang off `.dark`.
 */
const VIZ_STYLE = `
.dash-viz {
    --viz-1: #2a78d6; --viz-2: #1baf7a; --viz-3: #eda100; --viz-4: #008300;
    --viz-5: #4a3aa7; --viz-6: #e34948; --viz-7: #e87ba4; --viz-8: #eb6834;
    --viz-grid: #e1e0d9; --viz-axis: #c3c2b7; --viz-muted: #898781;
    --viz-surface: #fcfcfb; --viz-text: #52514e; --viz-border: rgba(11,11,11,0.10);
}
.dark .dash-viz {
    --viz-1: #3987e5; --viz-2: #199e70; --viz-3: #c98500; --viz-4: #008300;
    --viz-5: #9085e9; --viz-6: #e66767; --viz-7: #d55181; --viz-8: #d95926;
    --viz-grid: #2c2c2a; --viz-axis: #383835; --viz-muted: #898781;
    --viz-surface: #1a1a19; --viz-text: #c3c2b7; --viz-border: rgba(255,255,255,0.10);
}
`;

const SLOT = (i: number) => `var(--viz-${(i % 8) + 1})`;

/** Compact SAR for axis ticks: 1.2ألف / 3.4م. */
function compact(v: number): string {
    if (v >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}م`;
    if (v >= 1_000) return `${(v / 1_000).toFixed(1)}ألف`;
    return `${v}`;
}

function shortDate(iso: string): string {
    const d = new Date(iso);
    return `${d.getDate()}/${d.getMonth() + 1}`;
}

interface TooltipEntry {
    name?: string | number;
    value?: number;
    color?: string;
}

function VizTooltip({ active, payload, label }: { active?: boolean; payload?: TooltipEntry[]; label?: string }) {
    if (!active || !payload?.length) return null;
    return (
        <div
            className="rounded-md border px-3 py-2 text-xs shadow-sm"
            style={{ background: 'var(--viz-surface)', borderColor: 'var(--viz-border)', color: 'var(--viz-text)' }}
        >
            {label && <div className="mb-1 font-medium">{label}</div>}
            {payload.map((entry, i) => (
                <div key={i} className="flex items-center gap-2">
                    <span className="inline-block size-2 rounded-full" style={{ background: entry.color }} />
                    <span>{entry.name}:</span>
                    <span className="font-semibold">{formatCurrency(Number(entry.value ?? 0))}</span>
                </div>
            ))}
        </div>
    );
}

export function ChartCard({ title, children, className }: { title: string; children: React.ReactNode; className?: string }) {
    return (
        <Card className={className}>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
            </CardHeader>
            <CardContent className="dash-viz">
                <style dangerouslySetInnerHTML={{ __html: VIZ_STYLE }} />
                {children}
            </CardContent>
        </Card>
    );
}

function EmptyState() {
    return <div className="flex h-[220px] items-center justify-center text-sm text-muted-foreground">لا توجد بيانات</div>;
}

export function RevenueTrendChart({ data, singleSeries }: { data: DashboardTrendPoint[]; singleSeries?: boolean }) {
    const hasData = data.some((d) => d.product > 0 || d.service > 0);
    if (!hasData) return <EmptyState />;

    return (
        <ResponsiveContainer width="100%" height={260}>
            <LineChart data={data} margin={{ top: 8, right: 12, left: 4, bottom: 0 }}>
                <CartesianGrid stroke="var(--viz-grid)" vertical={false} />
                <XAxis
                    dataKey="date"
                    tickFormatter={shortDate}
                    tick={{ fill: 'var(--viz-muted)', fontSize: 11 }}
                    stroke="var(--viz-axis)"
                    interval="preserveStartEnd"
                    minTickGap={24}
                />
                <YAxis tickFormatter={compact} tick={{ fill: 'var(--viz-muted)', fontSize: 11 }} stroke="var(--viz-axis)" width={44} />
                <Tooltip content={<VizTooltip />} labelFormatter={(l) => shortDate(String(l))} />
                {!singleSeries && <Legend iconType="plainline" wrapperStyle={{ fontSize: 12 }} />}
                {!singleSeries && (
                    <Line type="monotone" dataKey="product" name="منتجات" stroke="var(--viz-1)" strokeWidth={2} dot={false} activeDot={{ r: 4 }} />
                )}
                <Line
                    type="monotone"
                    dataKey="service"
                    name={singleSeries ? 'مبيعاتي' : 'خدمات'}
                    stroke="var(--viz-2)"
                    strokeWidth={2}
                    dot={false}
                    activeDot={{ r: 4 }}
                />
            </LineChart>
        </ResponsiveContainer>
    );
}

export function SalesByTypeChart({ data }: { data: DashboardSalesByType }) {
    const slices = [
        { name: 'منتجات', value: data.product, fill: 'var(--viz-1)' },
        { name: 'خدمات', value: data.service, fill: 'var(--viz-2)' },
    ].filter((s) => s.value > 0);

    if (slices.length === 0) return <EmptyState />;

    return (
        <ResponsiveContainer width="100%" height={260}>
            <PieChart>
                <Pie data={slices} dataKey="value" nameKey="name" innerRadius={55} outerRadius={90} paddingAngle={2} stroke="var(--viz-surface)" strokeWidth={2}>
                    {slices.map((s, i) => (
                        <Cell key={i} fill={s.fill} />
                    ))}
                </Pie>
                <Tooltip content={<VizTooltip />} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
            </PieChart>
        </ResponsiveContainer>
    );
}

export function PaymentMethodsChart({ data }: { data: DashboardPaymentMethod[] }) {
    if (data.length === 0) return <EmptyState />;

    return (
        <ResponsiveContainer width="100%" height={Math.max(180, data.length * 46)}>
            <BarChart data={data} layout="vertical" margin={{ top: 4, right: 56, left: 8, bottom: 4 }}>
                <XAxis type="number" tickFormatter={compact} tick={{ fill: 'var(--viz-muted)', fontSize: 11 }} stroke="var(--viz-axis)" />
                <YAxis type="category" dataKey="name" tick={{ fill: 'var(--viz-text)', fontSize: 12 }} stroke="var(--viz-axis)" width={90} />
                <Tooltip content={<VizTooltip />} cursor={{ fill: 'var(--viz-border)' }} />
                <Bar dataKey="total" name="الإجمالي" radius={[0, 4, 4, 0]} barSize={22}>
                    {data.map((_, i) => (
                        <Cell key={i} fill={SLOT(i)} />
                    ))}
                    <LabelList
                        dataKey="total"
                        position="right"
                        formatter={(v) => compact(Number(v))}
                        style={{ fill: 'var(--viz-text)', fontSize: 11 }}
                    />
                </Bar>
            </BarChart>
        </ResponsiveContainer>
    );
}

export function TopServicesChart({ data }: { data: DashboardTopService[] }) {
    if (data.length === 0) return <EmptyState />;

    return (
        <ResponsiveContainer width="100%" height={Math.max(180, data.length * 46)}>
            <BarChart data={data} layout="vertical" margin={{ top: 4, right: 56, left: 8, bottom: 4 }}>
                <XAxis type="number" tickFormatter={compact} tick={{ fill: 'var(--viz-muted)', fontSize: 11 }} stroke="var(--viz-axis)" />
                <YAxis type="category" dataKey="name" tick={{ fill: 'var(--viz-text)', fontSize: 12 }} stroke="var(--viz-axis)" width={110} />
                <Tooltip content={<VizTooltip />} cursor={{ fill: 'var(--viz-border)' }} />
                {/* Single measure (magnitude ranking) → one sequential hue. */}
                <Bar dataKey="total" name="الإيراد" fill="var(--viz-1)" radius={[0, 4, 4, 0]} barSize={22}>
                    <LabelList
                        dataKey="total"
                        position="right"
                        formatter={(v) => compact(Number(v))}
                        style={{ fill: 'var(--viz-text)', fontSize: 11 }}
                    />
                </Bar>
            </BarChart>
        </ResponsiveContainer>
    );
}
