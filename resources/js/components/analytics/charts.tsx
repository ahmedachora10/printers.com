import { Button } from '@/components/ui/button';
import { ChartCard, compact, EmptyState, SLOT, VizTooltip } from '@/components/viz';
import { exportChartPng } from '@/lib/export-chart';
import { type AnalyticsPointsMonth, type AnalyticsTierSlice } from '@/types/analytics';
import { ImageDown } from 'lucide-react';
import { useRef, useState } from 'react';
import { CartesianGrid, Cell, Legend, Line, LineChart, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

export interface ChartLegendEntry {
    label: string;
    /** CSS custom property name resolved at export time, e.g. "--viz-1". */
    color: string;
}

/**
 * ChartCard with a PNG export button. The exported image gets the card title
 * and, because the Recharts HTML legend is outside the SVG, the passed legend
 * entries drawn onto the canvas.
 */
export function ExportChartCard({
    title,
    filename,
    legend,
    children,
    className,
}: {
    title: string;
    filename: string;
    legend?: ChartLegendEntry[];
    children: React.ReactNode;
    className?: string;
}) {
    const bodyRef = useRef<HTMLDivElement>(null);
    const [busy, setBusy] = useState(false);

    async function handleExport() {
        if (!bodyRef.current || busy) return;
        setBusy(true);
        try {
            await exportChartPng(bodyRef.current, { filename, title, legend });
        } finally {
            setBusy(false);
        }
    }

    return (
        <ChartCard
            title={title}
            className={className}
            action={
                <Button variant="ghost" size="icon" onClick={handleExport} disabled={busy} title="تصدير PNG" aria-label={`تصدير ${title} كصورة`}>
                    <ImageDown className="size-4" />
                </Button>
            }
        >
            <div ref={bodyRef}>{children}</div>
        </ChartCard>
    );
}

const TIER_LABELS: Record<AnalyticsTierSlice['tier'], string> = {
    none: 'بدون فئة',
    bronze: 'برونزي',
    silver: 'فضي',
    gold: 'ذهبي',
};

export function TierDistributionChart({ data }: { data: AnalyticsTierSlice[] }) {
    const slices = data
        .map((s, i) => ({ name: TIER_LABELS[s.tier], value: s.count, fill: SLOT(i) }))
        .filter((s) => s.value > 0);

    if (slices.length === 0) return <EmptyState />;

    return (
        <ResponsiveContainer width="100%" height={260}>
            <PieChart>
                <Pie data={slices} dataKey="value" nameKey="name" innerRadius={55} outerRadius={90} paddingAngle={2} stroke="var(--viz-surface)" strokeWidth={2}>
                    {slices.map((s, i) => (
                        <Cell key={i} fill={s.fill} />
                    ))}
                </Pie>
                <Tooltip content={<VizTooltip format={(v) => `${v.toLocaleString('ar')} عميل`} />} />
                <Legend wrapperStyle={{ fontSize: 12 }} />
            </PieChart>
        </ResponsiveContainer>
    );
}

/** Month tick: "2026-03" → "3/2026". */
function shortMonth(key: string): string {
    const [year, month] = key.split('-');
    return `${Number(month)}/${year}`;
}

export function PointsMonthlyChart({ data }: { data: AnalyticsPointsMonth[] }) {
    const hasData = data.some((d) => d.earned > 0 || d.redeemed > 0);
    if (!hasData) return <EmptyState />;

    const formatPoints = (v: number) => `${v.toLocaleString('ar')} نقطة`;

    return (
        <ResponsiveContainer width="100%" height={260}>
            <LineChart data={data} margin={{ top: 8, right: 12, left: 4, bottom: 0 }}>
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
                <Tooltip content={<VizTooltip format={formatPoints} />} labelFormatter={(l) => shortMonth(String(l))} />
                <Legend iconType="plainline" wrapperStyle={{ fontSize: 12 }} />
                <Line type="monotone" dataKey="earned" name="نقاط مكتسبة" stroke="var(--viz-1)" strokeWidth={2} dot={false} activeDot={{ r: 4 }} />
                <Line type="monotone" dataKey="redeemed" name="نقاط مستبدلة" stroke="var(--viz-2)" strokeWidth={2} dot={false} activeDot={{ r: 4 }} />
            </LineChart>
        </ResponsiveContainer>
    );
}
