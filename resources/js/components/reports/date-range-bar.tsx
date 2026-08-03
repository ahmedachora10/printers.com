import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router } from '@inertiajs/react';

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

interface Props {
    /** Route the range is applied against. */
    url: string;
    /** Currently applied range, as YYYY-MM-DD. */
    from: string;
    to: string;
    /** Other applied filters to carry along so the range doesn't drop them. */
    params?: Record<string, string>;
}

/**
 * Always-visible date range: four shortcuts plus من/إلى, applied immediately.
 * Sits above a report so the common case (today, yesterday, this month) never
 * costs a trip through the filter modal.
 */
export default function DateRangeBar({ url, from, to, params = {} }: Props) {
    const go = (next: { from: string; to: string }) =>
        router.get(url, { ...params, ...next }, { preserveState: true, preserveScroll: true, replace: true });

    const isCurrent = (range: { from: string; to: string }) => range.from === from && range.to === to;

    return (
        <div className="flex flex-wrap items-end gap-x-4 gap-y-3">
            <div className="flex flex-wrap gap-1.5">
                {SHORTCUTS.map((shortcut) => {
                    const range = shortcut.range();
                    return (
                        <Button
                            key={shortcut.label}
                            type="button"
                            size="sm"
                            variant={isCurrent(range) ? 'default' : 'outline'}
                            onClick={() => go(range)}
                        >
                            {shortcut.label}
                        </Button>
                    );
                })}
            </div>

            <div className="flex items-end gap-2">
                <div className="space-y-1">
                    <Label htmlFor="range-from" className="text-muted-foreground text-xs">
                        من
                    </Label>
                    <Input
                        id="range-from"
                        type="date"
                        value={from}
                        max={to || undefined}
                        onChange={(e) => e.target.value && go({ from: e.target.value, to })}
                        className="h-8 w-36"
                        dir="ltr"
                    />
                </div>
                <div className="space-y-1">
                    <Label htmlFor="range-to" className="text-muted-foreground text-xs">
                        إلى
                    </Label>
                    <Input
                        id="range-to"
                        type="date"
                        value={to}
                        min={from || undefined}
                        onChange={(e) => e.target.value && go({ from, to: e.target.value })}
                        className="h-8 w-36"
                        dir="ltr"
                    />
                </div>
            </div>
        </div>
    );
}
