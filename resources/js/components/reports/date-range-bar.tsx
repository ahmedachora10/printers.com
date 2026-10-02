import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type UseReportFilters } from '@/hooks/use-report-filters';
import { shiftDay } from '@/lib/utils';
import { ChevronLeft, ChevronRight } from 'lucide-react';

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

/**
 * The range one step back (-1) or forward (+1), same length (تاسك 138). A range
 * that starts on the 1st and stays inside one month (هذا الشهر، الشهر الماضي)
 * steps a whole calendar month instead — Aug 1–28 back is July 1–31, not Jul 4–31.
 * Forward never runs past today.
 */
function step(from: string, to: string, dir: -1 | 1): { from: string; to: string } {
    const today = iso(new Date());
    const clamp = (range: { from: string; to: string }) => ({ from: range.from, to: range.to > today ? today : range.to });

    if (from.endsWith('-01') && from !== to && from.slice(0, 7) === to.slice(0, 7)) {
        const [y, m] = from.split('-').map(Number);
        // m is 1-based, so Date.UTC(y, m - 1) is this month; day 0 of the month after = the target's last day.
        const start = new Date(Date.UTC(y, m - 1 + dir, 1)).toISOString().slice(0, 10);
        const end = new Date(Date.UTC(y, m + dir, 0)).toISOString().slice(0, 10);
        return clamp({ from: start, to: end });
    }

    const days = Math.round((Date.parse(to) - Date.parse(from)) / 86_400_000) + 1;
    return clamp({ from: shiftDay(from, dir * days), to: shiftDay(to, dir * days) });
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
}

/**
 * Always-visible date range: six shortcuts (تاسك 162: «الشهر الماضي» في كل القوائم) plus من/إلى, applied immediately.
 * Sits above a report so the common case (today, yesterday, this month) never
 * costs a trip through the filter modal. Navigating through useReportFilters
 * keeps the page's other filters applied.
 */
export default function DateRangeBar({ filters, from, to, fromKey = 'from', toKey = 'to' }: Props) {
    const go = (next: { from: string; to: string }) => filters.replaceMany({ [fromKey]: next.from, [toKey]: next.to });

    const isCurrent = (range: { from: string; to: string }) => range.from === from && range.to === to;

    // Arrows as on the reconciliation screen: previous before من, next after إلى.
    const canStep = from !== '' && to !== '';
    const arrow = (dir: -1 | 1) => (
        <Button
            type="button"
            variant="outline"
            size="icon"
            className="size-9 shrink-0 sm:size-8"
            aria-label={dir < 0 ? 'الفترة السابقة' : 'الفترة التالية'}
            disabled={dir > 0 && to >= iso(new Date())}
            onClick={() => go(step(from, to, dir))}
        >
            {dir < 0 ? <ChevronRight className="size-4" /> : <ChevronLeft className="size-4" />}
        </Button>
    );

    return (
        // w-full + min-w-0 below sm: as a flex item this bar would otherwise hold
        // its content width (~346px) and push a 360px page sideways.
        <div className="flex w-full min-w-0 flex-wrap items-end gap-x-4 gap-y-3 sm:w-auto">
            <div className="flex flex-wrap gap-1.5">
                {SHORTCUTS.map((shortcut) => {
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
                    <div className="flex gap-1">
                        {canStep && arrow(-1)}
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
                </div>
                <div className="min-w-0 space-y-1">
                    <Label htmlFor={`range-${toKey}`} className="text-muted-foreground text-xs">
                        إلى
                    </Label>
                    <div className="flex gap-1">
                        <Input
                            id={`range-${toKey}`}
                            type="date"
                            value={to}
                            min={from || undefined}
                            onChange={(e) => e.target.value && go({ from, to: e.target.value })}
                            className="h-9 w-full sm:h-8 sm:w-36"
                            dir="ltr"
                        />
                        {canStep && arrow(1)}
                    </div>
                </div>
            </div>
        </div>
    );
}
