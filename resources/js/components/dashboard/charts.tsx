import { compact, EmptyState, shortDate, SLOT, VizTooltip } from '@/components/viz';
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

export { ChartCard } from '@/components/viz';

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
                <YAxis type="category" dataKey="name" tick={{ fill: 'var(--viz-text)', fontSize: 12 }} stroke="var(--viz-axis)" width={110} />
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
                <YAxis type="category" dataKey="name" tick={{ fill: 'var(--viz-text)', fontSize: 12 }} stroke="var(--viz-axis)" width={120} />
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
