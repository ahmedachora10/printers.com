import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type UseReportFilters } from '@/hooks/use-report-filters';

/** Local YYYY-MM-DD — never toISOString(), which shifts across the UTC boundary. */
function iso(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function shift(days: number): Date {
    const d = new Date();
    d.setDate(d.getDate() + days);
    return d;
}

function startOfMonth(): Date {
    const d = new Date();
    d.setDate(1);
    return d;
}

interface Shortcut {
    label: string;
    range: () => { from: string; to: string };
}

const SHORTCUTS: Shortcut[] = [
    { label: 'اليوم', range: () => ({ from: iso(new Date()), to: iso(new Date()) }) },
    { label: 'أمس', range: () => ({ from: iso(shift(-1)), to: iso(shift(-1)) }) },
    { label: 'آخر 7 أيام', range: () => ({ from: iso(shift(-6)), to: iso(new Date()) }) },
    { label: 'هذا الشهر', range: () => ({ from: iso(startOfMonth()), to: iso(new Date()) }) },
];

/** Wider ranges — only useful on long-lived lists, so they are opt-in. */
const EXTENDED_SHORTCUTS: Shortcut[] = [
    { label: 'آخر 30 يوماً', range: () => ({ from: iso(shift(-29)), to: iso(new Date()) }) },
    {
        label: 'الشهر الماضي',
        range: () => {
            const now = new Date();
            // Day 0 of this month is the last day of the previous one.
            return {
                from: iso(new Date(now.getFullYear(), now.getMonth() - 1, 1)),
                to: iso(new Date(now.getFullYear(), now.getMonth(), 0)),
            };
        },
    },
];

interface Props {
    /** The page's filter state — the single source of truth for every filter. */
    filters: UseReportFilters;
    /** Currently applied range, as YYYY-MM-DD. */
    from: string;
    to: string;
    /** Query keys the range is stored under — list pages use date_from/date_to. */
    fromKey?: string;
    toKey?: string;
    /** Append آخر 30 يوماً / الشهر الماضي to the shortcuts. */
    extended?: boolean;
}

/**
 * Always-visible date range: four shortcuts plus من/إلى, applied immediately.
 * Sits above a report so the common case (today, yesterday, this month) never
 * costs a trip through the filter modal. Navigating through useReportFilters
 * keeps the page's other filters applied.
 */
export default function DateRangeBar({ filters, from, to, fromKey = 'from', toKey = 'to', extended = false }: Props) {
    const go = (next: { from: string; to: string }) => filters.replaceMany({ [fromKey]: next.from, [toKey]: next.to });

    const isCurrent = (range: { from: string; to: string }) => range.from === from && range.to === to;

    const shortcuts = extended ? [...SHORTCUTS, ...EXTENDED_SHORTCUTS] : SHORTCUTS;

    return (
        // w-full + min-w-0 below sm: as a flex item this bar would otherwise hold
        // its content width (~346px) and push a 360px page sideways.
        <div className="flex w-full min-w-0 flex-wrap items-end gap-x-4 gap-y-3 sm:w-auto">
            <div className="flex flex-wrap gap-1.5">
                {shortcuts.map((shortcut) => {
                    const range = shortcut.range();
                    return (
                        <Button
                            key={shortcut.label}
                            type="button"
                            size="sm"
                            variant={isCurrent(range) ? 'default' : 'outline'}
                            className="h-9 sm:h-8"
                            onClick={() => go(range)}
                        >
                            {shortcut.label}
                        </Button>
                    );
                })}
            </div>

            {/* A date input will not render below ~170px, so on a phone the two
                pickers stack instead of being squeezed side by side. */}
            <div className="grid w-full grid-cols-1 gap-2 sm:flex sm:w-auto sm:items-end">
                <div className="min-w-0 space-y-1">
                    <Label htmlFor={`range-${fromKey}`} className="text-muted-foreground text-xs">
                        من
                    </Label>
                    <Input
                        id={`range-${fromKey}`}
                        type="date"
                        value={from}
                        max={to || undefined}
                        onChange={(e) => e.target.value && go({ from: e.target.value, to })}
                        className="h-9 w-full sm:h-8 sm:w-36"
                        dir="ltr"
                    />
                </div>
                <div className="min-w-0 space-y-1">
                    <Label htmlFor={`range-${toKey}`} className="text-muted-foreground text-xs">
                        إلى
                    </Label>
                    <Input
                        id={`range-${toKey}`}
                        type="date"
                        value={to}
                        min={from || undefined}
                        onChange={(e) => e.target.value && go({ from, to: e.target.value })}
                        className="h-9 w-full sm:h-8 sm:w-36"
                        dir="ltr"
                    />
                </div>
            </div>
        </div>
    );
}
