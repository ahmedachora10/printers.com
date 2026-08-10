import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn, formatCurrency } from '@/lib/utils';
import { ChevronDown, FileText, Minus, Plus, Trash2 } from 'lucide-react';
import { Fragment, useCallback, useEffect, useRef, useState, type ReactNode } from 'react';

export interface PosCartLineBase {
    key: string;
    name: string;
    unitPrice: number;
    qty: number;
    discountPct: number;
    isManual: boolean;
}

interface PosCartTableProps<T extends PosCartLineBase> {
    lines: T[];
    /** header label for the first column, e.g. "الخدمة" / "المنتج" */
    itemLabel: string;
    /** hint shown in the empty state */
    emptyHint: string;
    /** validation error rendered above the table */
    error?: string;
    /** show the picker dropdown instead of a static name (manual / unselected lines) */
    isLineSelectable: (line: T) => boolean;
    /** the service/product picker element for a selectable line */
    renderLineSelect: (line: T) => ReactNode;
    /** sub-text under the name, e.g. commission % or SKU */
    renderLineMeta: (line: T) => ReactNode;
    /** whether the unit price is editable (otherwise displayed read-only) */
    isPriceEditable: (line: T) => boolean;
    /** max allowed discount % for the line */
    getMaxDiscount: (line: T) => number;
    getLineTotal: (line: T) => number;
    onQtyChange: (line: T, delta: number) => void;
    onPriceChange: (line: T, price: number) => void;
    onDiscountChange: (line: T, value: number) => void;
    onRemove: (key: string) => void;
    onAddManual: () => void;
    /** header label for the optional agent column (enables the column when set together with renderLineAgent) */
    lineAgentLabel?: string;
    /** the per-line agent (commission owner) picker, rendered in its own column beside price */
    renderLineAgent?: (line: T) => ReactNode;
    /** optional collapsible detail panel rendered under a line (dimensions, commission owner …) */
    renderLineDetails?: (line: T) => ReactNode;
    /** compact chips describing the collapsed panel — shown on the toggle bar */
    renderLineSummary?: (line: T) => ReactNode;
    /** open a line's panel the first time it appears (e.g. a line still missing required input) */
    isLineDetailsInitiallyOpen?: (line: T) => boolean;
}

function QuantityStepper({ qty, onChange }: { qty: number; onChange: (delta: number) => void }) {
    return (
        <div className="bg-background inline-flex items-center rounded-md border">
            <Button
                type="button"
                size="icon"
                variant="ghost"
                className="size-11 rounded-none md:size-7"
                onClick={() => onChange(-1)}
                aria-label="إنقاص الكمية"
            >
                <Minus className="size-3" />
            </Button>
            <span className="w-8 text-center text-sm font-medium tabular-nums">{qty}</span>
            <Button
                type="button"
                size="icon"
                variant="ghost"
                className="size-11 rounded-none md:size-7"
                onClick={() => onChange(1)}
                aria-label="زيادة الكمية"
            >
                <Plus className="size-3" />
            </Button>
        </div>
    );
}

/**
 * A compact fact about a cart line, shown on the collapsed details bar so the
 * cashier can read a line's hidden settings without opening it.
 */
export function LineChip({ tone = 'neutral', children }: { tone?: 'neutral' | 'info' | 'warning'; children: ReactNode }) {
    return (
        <span
            className={cn(
                'inline-flex max-w-[16rem] items-center gap-1 truncate rounded-full border px-2 py-0.5 text-[11px] leading-4 font-medium',
                tone === 'neutral' && 'bg-background text-muted-foreground',
                tone === 'info' && 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-400',
                tone === 'warning' && 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-400',
            )}
        >
            {children}
        </span>
    );
}

/** Field label + control pairing used inside a line's detail panel. */
export function LineField({ label, htmlFor, children }: { label: ReactNode; htmlFor?: string; children: ReactNode }) {
    return (
        <div className="space-y-1.5">
            <label htmlFor={htmlFor} className="text-muted-foreground block text-[11px] font-medium">
                {label}
            </label>
            {children}
        </div>
    );
}

/** Read-only computed figure, shaped like the inputs beside it so the row aligns. */
export function LineReadout({ children, tone = 'neutral' }: { children: ReactNode; tone?: 'neutral' | 'info' }) {
    return (
        <div
            className={cn(
                'flex h-9 items-center rounded-md border border-dashed px-3 text-sm font-semibold tabular-nums',
                tone === 'neutral' && 'bg-muted/50',
                tone === 'info' && 'border-sky-500/40 bg-sky-500/10 text-sky-700 dark:text-sky-400',
            )}
        >
            {children}
        </div>
    );
}

/** Titled group inside a detail panel — keeps unrelated settings visibly apart. */
export function LineSection({ title, aside, children }: { title: ReactNode; aside?: ReactNode; children: ReactNode }) {
    return (
        <section className="space-y-2 p-3">
            <div className="flex items-center justify-between gap-2">
                <h4 className="text-xs font-semibold">{title}</h4>
                {aside && <span className="text-muted-foreground text-[11px]">{aside}</span>}
            </div>
            {children}
        </section>
    );
}

export function PosCartTable<T extends PosCartLineBase>({
    lines,
    itemLabel,
    emptyHint,
    error,
    isLineSelectable,
    renderLineSelect,
    renderLineMeta,
    isPriceEditable,
    getMaxDiscount,
    getLineTotal,
    onQtyChange,
    onPriceChange,
    onDiscountChange,
    onRemove,
    onAddManual,
    lineAgentLabel,
    renderLineAgent,
    renderLineDetails,
    renderLineSummary,
    isLineDetailsInitiallyOpen,
}: PosCartTableProps<T>) {
    // The agent column is opt-in; other POS screens (e.g. products) omit it.
    const hasAgentColumn = !!renderLineAgent;
    const detailsColSpan = hasAgentColumn ? 7 : 6;

    // Panels stay closed so the cart reads as one list; the caller may ask for a
    // line to open on arrival (a sqm line still missing its dimensions), which is
    // applied once — after that the cashier's own toggling wins.
    const [openKeys, setOpenKeys] = useState<Set<string>>(new Set());
    const seenKeys = useRef(new Set<string>());
    const initiallyOpen = useRef(isLineDetailsInitiallyOpen);
    initiallyOpen.current = isLineDetailsInitiallyOpen;

    useEffect(() => {
        const toOpen = lines.filter((line) => !seenKeys.current.has(line.key) && initiallyOpen.current?.(line)).map((line) => line.key);
        lines.forEach((line) => seenKeys.current.add(line.key));
        if (toOpen.length > 0) {
            setOpenKeys((prev) => new Set([...prev, ...toOpen]));
        }
    }, [lines]);

    const toggleLine = useCallback((key: string) => {
        setOpenKeys((prev) => {
            const next = new Set(prev);
            if (!next.delete(key)) next.add(key);
            return next;
        });
    }, []);

    // Cell contents are shared by the desktop table and the stacked small-screen
    // cards, so a line renders identically in both.
    const nameControl = (line: T) =>
        isLineSelectable(line) ? (
            renderLineSelect(line)
        ) : (
            <div className="min-w-0">
                <p className="truncate text-sm font-medium">{line.name}</p>
                <p className="text-muted-foreground text-xs">{renderLineMeta(line)}</p>
            </div>
        );

    const priceControl = (line: T) =>
        isPriceEditable(line) ? (
            <Input
                type="number"
                min={0}
                step="0.01"
                value={line.unitPrice}
                onChange={(e) => onPriceChange(line, Math.max(0, Number(e.target.value) || 0))}
                className="h-11 text-center md:h-8"
            />
        ) : (
            <span className="flex h-11 items-center text-sm tabular-nums md:h-8 md:justify-center">{formatCurrency(line.unitPrice)}</span>
        );

    const discountControl = (line: T) => (
        <Input
            type="number"
            min={0}
            max={getMaxDiscount(line)}
            value={line.discountPct}
            onChange={(e) => onDiscountChange(line, Number(e.target.value))}
            className="h-11 text-center md:h-8"
        />
    );

    const removeControl = (line: T) => (
        <Button
            type="button"
            size="icon"
            variant="ghost"
            className="text-muted-foreground hover:text-destructive size-11 md:size-7"
            onClick={() => onRemove(line.key)}
            aria-label={`حذف ${line.name || itemLabel}`}
        >
            <Trash2 className="size-4" />
        </Button>
    );

    /** The toggle bar + panel shown under a line that has details to edit. */
    const detailsBlock = (line: T, details: ReactNode) => {
        const isOpen = openKeys.has(line.key);
        const summary = renderLineSummary?.(line);

        return (
            <div className="space-y-2">
                <button
                    type="button"
                    onClick={() => toggleLine(line.key)}
                    aria-expanded={isOpen}
                    className="hover:bg-accent/60 flex min-h-11 w-full items-center gap-2 rounded-md px-2 py-1.5 text-right transition md:min-h-0"
                >
                    <ChevronDown className={cn('text-muted-foreground size-3.5 shrink-0 transition-transform', isOpen && 'rotate-180')} />
                    <span className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                        {summary ?? <span className="text-muted-foreground text-[11px]">لا توجد تفاصيل مضافة</span>}
                    </span>
                    <span className="text-muted-foreground shrink-0 text-[11px]">{isOpen ? 'إخفاء' : 'تفاصيل'}</span>
                </button>

                {isOpen && <div className="bg-background divide-y rounded-lg border">{details}</div>}
            </div>
        );
    };

    return (
        <div className="space-y-3">
            {error && <p className="bg-destructive/10 text-destructive rounded-md p-2 text-sm">{error}</p>}

            {lines.length === 0 ? (
                <div className="text-muted-foreground flex flex-col items-center gap-3 py-16 text-center">
                    <FileText className="size-12 opacity-40" />
                    <p className="text-sm">{emptyHint}</p>
                    <Button type="button" variant="outline" size="sm" onClick={onAddManual}>
                        <Plus className="size-4" /> سطر يدوي
                    </Button>
                </div>
            ) : (
                <>
                    {/* Desktop — the full column grid */}
                    <div className="hidden overflow-hidden rounded-lg border md:block">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-muted/40 hover:bg-muted/40">
                                    <TableHead className="h-9 text-right">{itemLabel}</TableHead>
                                    <TableHead className="h-9 w-[8rem] text-center">الكمية</TableHead>
                                    {/* الأسعار المُدخلة شاملة للضريبة — التنويه هنا لأن هذا هو الحقل الذي يكتب فيه الموظف. */}
                                    <TableHead className="h-9 w-[7rem] text-center leading-tight">
                                        السعر
                                        <span className="text-muted-foreground block text-[10px] font-normal">شامل الضريبة</span>
                                    </TableHead>
                                    {hasAgentColumn && <TableHead className="h-9 w-[9rem] text-center">{lineAgentLabel}</TableHead>}
                                    <TableHead className="h-9 w-[6rem] text-center">خصم %</TableHead>
                                    <TableHead className="h-9 w-[7rem] text-center">الإجمالي</TableHead>
                                    <TableHead className="h-9 w-[3rem]" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {lines.map((line) => {
                                    const details = renderLineDetails?.(line);

                                    return (
                                        <Fragment key={line.key}>
                                            <TableRow className={cn(details && 'border-b-0 hover:bg-transparent')}>
                                                <TableCell className="p-2 align-middle">{nameControl(line)}</TableCell>

                                                <TableCell className="p-2 text-center">
                                                    <QuantityStepper qty={line.qty} onChange={(delta) => onQtyChange(line, delta)} />
                                                </TableCell>

                                                <TableCell className="p-2 text-center">{priceControl(line)}</TableCell>

                                                {hasAgentColumn && <TableCell className="p-2 text-center">{renderLineAgent!(line)}</TableCell>}

                                                <TableCell className="p-2 text-center">{discountControl(line)}</TableCell>

                                                <TableCell className="p-2 text-center text-sm font-semibold tabular-nums">
                                                    {formatCurrency(getLineTotal(line))}
                                                </TableCell>

                                                <TableCell className="p-2 text-center">{removeControl(line)}</TableCell>
                                            </TableRow>

                                            {details && (
                                                <TableRow className="hover:bg-transparent">
                                                    <TableCell colSpan={detailsColSpan} className="px-2 pt-0 pb-2">
                                                        {detailsBlock(line, details)}
                                                    </TableCell>
                                                </TableRow>
                                            )}
                                        </Fragment>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>

                    {/* Small screens — one card per line, labelled instead of columned */}
                    <div className="space-y-3 md:hidden">
                        {lines.map((line) => {
                            const details = renderLineDetails?.(line);

                            return (
                                <div key={line.key} className="space-y-3 rounded-lg border p-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0 flex-1">{nameControl(line)}</div>
                                        {removeControl(line)}
                                    </div>

                                    <div className="grid grid-cols-2 gap-3">
                                        <LineField label="الكمية">
                                            <QuantityStepper qty={line.qty} onChange={(delta) => onQtyChange(line, delta)} />
                                        </LineField>
                                        <LineField label="السعر (شامل الضريبة)">{priceControl(line)}</LineField>
                                        {hasAgentColumn && (
                                            <div className="col-span-2">
                                                <LineField label={lineAgentLabel}>{renderLineAgent!(line)}</LineField>
                                            </div>
                                        )}
                                        <LineField label="خصم %">{discountControl(line)}</LineField>
                                        <LineField label="الإجمالي">
                                            <span className="flex h-8 items-center text-sm font-semibold tabular-nums">
                                                {formatCurrency(getLineTotal(line))}
                                            </span>
                                        </LineField>
                                    </div>

                                    {details && detailsBlock(line, details)}
                                </div>
                            );
                        })}
                    </div>

                    <Button type="button" variant="outline" size="sm" className="h-11 w-full md:h-8" onClick={onAddManual}>
                        <Plus className="size-4" /> سطر يدوي
                    </Button>
                </>
            )}
        </div>
    );
}
