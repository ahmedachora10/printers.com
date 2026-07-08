import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { formatCurrency } from '@/lib/utils';

/**
 * Theme-aware chart palette. Values come from the validated data-viz reference
 * palette (categorical slots in fixed order, plus recessive chrome). Defined as
 * CSS custom properties so light/dark swap in one place and Recharts marks
 * reference them by role via var(--…). The app toggles a `.dark` class on
 * <html> (see use-appearance), so dark steps hang off `.dark`.
 */
export const VIZ_STYLE = `
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

export const SLOT = (i: number) => `var(--viz-${(i % 8) + 1})`;

/** Compact SAR for axis ticks & bar labels: 3.4م / 1.2ألف / 669 (rounded, no float noise). */
export function compact(v: number | string): string {
    const n = Number(v);
    if (!Number.isFinite(n)) return '';
    const abs = Math.abs(n);
    if (abs >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}م`;
    if (abs >= 1_000) return `${(n / 1_000).toFixed(1)}ألف`;
    return `${Math.round(n)}`;
}

export function shortDate(iso: string): string {
    const d = new Date(iso);
    return `${d.getDate()}/${d.getMonth() + 1}`;
}

interface TooltipEntry {
    name?: string | number;
    value?: number;
    color?: string;
}

export function VizTooltip({
    active,
    payload,
    label,
    format = (v) => formatCurrency(v),
}: {
    active?: boolean;
    payload?: TooltipEntry[];
    label?: string;
    format?: (value: number) => string;
}) {
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
                    <span className="font-semibold">{format(Number(entry.value ?? 0))}</span>
                </div>
            ))}
        </div>
    );
}

export function ChartCard({
    title,
    children,
    className,
    action,
}: {
    title: string;
    children: React.ReactNode;
    className?: string;
    action?: React.ReactNode;
}) {
    return (
        <Card className={className}>
            <CardHeader className={action ? 'flex-row items-center justify-between space-y-0' : undefined}>
                <CardTitle>{title}</CardTitle>
                {action}
            </CardHeader>
            {/* Recharts lays out in LTR SVG coordinates; forcing dir=ltr keeps the
                category axis on the left and value labels at the bar tips instead
                of colliding with RTL-flipped text. Arabic labels still render fine. */}
            <CardContent className="dash-viz">
                <style dangerouslySetInnerHTML={{ __html: VIZ_STYLE }} />
                <div dir="ltr">{children}</div>
            </CardContent>
        </Card>
    );
}

export function EmptyState() {
    return <div className="flex h-[220px] items-center justify-center text-sm text-muted-foreground">لا توجد بيانات</div>;
}
