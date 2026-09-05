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

/**
 * خطأ سعر السطر: `text` سطرٌ قصير يُقرأ تحت الحقل، و`detail` شرحه الكامل يظهر
 * عند المرور بالمؤشّر (تاسك 81) — النصّ يُختصر ولا تُتلف معلومته.
 */
export interface PosPriceError {
    text: string;
    detail?: string;
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
    /**
     * رسالة خطأ على سعر السطر — تُلوّن الحقل وتُكتب تحته، ويمنعها المستدعي من
     * الحفظ. تُعاد null حين لا شيء عليه (وهو الشائع).
     */
    getPriceError?: (line: T) => PosPriceError | null;
    /** تنويه صغير أسفل حقل السعر — وحدة القياس مثلاً: «ر.س/م²». */
    getPriceHint?: (line: T) => string | null;
    /** max allowed discount % for the line */
    getMaxDiscount: (line: T) => number;
    getLineTotal: (line: T) => number;
    /**
     * يحلّ محلّ مِعداد الكمية لسطرٍ كميتُه مشتقّة لا مكتوبة — كسطر المنتج المسعّر
     * بالمتر المربع، مساحتُه تأتي من المقاس داخل تفاصيل السطر (تاسك 51).
     */
    renderQtyControl?: (line: T) => ReactNode;
    onQtyChange: (line: T, delta: number) => void;
    onPriceChange: (line: T, price: number) => void;
    onDiscountChange: (line: T, value: number) => void;
    onRemove: (key: string) => void;
    onAddManual: () => void;
    /** optional collapsible detail panel rendered under a line (dimensions, commission owner …) */
    renderLineDetails?: (line: T) => ReactNode;
    /** compact chips describing the collapsed panel — shown on the toggle bar */
    renderLineSummary?: (line: T) => ReactNode;
    /** open a line's panel the first time it appears (e.g. a line still missing required input) */
    isLineDetailsInitiallyOpen?: (line: T) => boolean;
}

/**
 * ارتفاع كل عنصر تحكّم في صفّ السلة — المِعداد وحقل السعر وحقل الخصم والإجمالي.
 * واحدٌ لها جميعاً كي تقف على خط أفقي واحد (تاسك 61): بلا هذا يعلو المِعداد
 * بارتفاع حدوده عن الحقول المجاورة له.
 */
const CONTROL_HEIGHT = 'h-11 md:h-8';

function QuantityStepper({ qty, onChange }: { qty: number; onChange: (delta: number) => void }) {
    return (
        <div className={cn('bg-background inline-flex items-center overflow-hidden rounded-md border', CONTROL_HEIGHT)}>
            <Button
                type="button"
                size="icon"
                variant="ghost"
                className="h-full w-11 rounded-none md:w-7"
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
                className="h-full w-11 rounded-none md:w-7"
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

/**
 * لون نصوص المساعدة داخل تفاصيل السطر (تاسك 62). أفتحُ بوضوح من
 * `text-muted-foreground` لأن أكثرها أرقامٌ توضيحية — «المساحة 1 م² × 5.00 =
 * 5.00 للقطعة» — فبلونٍ داكن تُقرأ قيمةً بين القيم لا شرحاً لها. النسبة 75%
 * تُبقي التباين فوق 4.5:1 في الوضع الفاتح فلا يصير الشرح غير مقروء.
 *
 * على العنصر نفسه لا على أبٍ يلفّه: الشفافية على الأب تُبهت معها الأزرار
 * والروابط الداخلة فيه («استعادة سعر الخدمة»)، وهي إجراءات لا شروح.
 */
export const LINE_HINT_CLASS = 'text-muted-foreground/75';

/** Explanatory note under a control in a detail panel — deliberately faint. */
export function LineHint({ children, className }: { children: ReactNode; className?: string }) {
    return <p className={cn(LINE_HINT_CLASS, 'text-[11px]', className)}>{children}</p>;
}

/** Titled group inside a detail panel — keeps unrelated settings visibly apart. */
export function LineSection({ title, aside, children }: { title: ReactNode; aside?: ReactNode; children: ReactNode }) {
    return (
        <section className="space-y-2 p-3">
            <div className="flex items-center justify-between gap-2">
                <h4 className="text-xs font-semibold">{title}</h4>
                {aside && <span className={cn(LINE_HINT_CLASS, 'text-[11px]')}>{aside}</span>}
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
    getPriceError,
    getPriceHint,
    getMaxDiscount,
    getLineTotal,
    renderQtyControl,
    onQtyChange,
    onPriceChange,
    onDiscountChange,
    onRemove,
    onAddManual,
    renderLineDetails,
    renderLineSummary,
    isLineDetailsInitiallyOpen,
}: PosCartTableProps<T>) {
    // الجدول ستة أعمدة ثابتة — «صاحب العمولة» انتقل إلى القائمة السفلية (تاسك 56).
    const detailsColSpan = 6;

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

    // تنويه السعر وخطؤه يُكتبان **تحت** الحقل بلا أن يزيحاه: الخلايا الثلاث
    // مُحاذاة للأعلى (align-top) فيبقى الحقل نفسه على خط جاريه مهما طال ذيله.
    const priceControl = (line: T) => {
        const priceError = getPriceError?.(line) ?? null;
        const priceHint = getPriceHint?.(line) ?? null;

        if (!isPriceEditable(line)) {
            return (
                <span className={cn('flex items-center text-sm tabular-nums md:justify-center', CONTROL_HEIGHT)}>
                    {formatCurrency(line.unitPrice)}
                </span>
            );
        }

        return (
            <div>
                <Input
                    type="number"
                    min={0}
                    step="0.01"
                    value={line.unitPrice}
                    onChange={(e) => onPriceChange(line, Math.max(0, Number(e.target.value) || 0))}
                    aria-invalid={priceError ? true : undefined}
                    className={cn(CONTROL_HEIGHT, 'text-center', priceError && 'border-destructive text-destructive focus-visible:ring-destructive')}
                />
                {priceHint && !priceError && <p className={cn(LINE_HINT_CLASS, 'mt-1 text-[10px] leading-tight')}>{priceHint}</p>}
                {priceError && (
                    <p className="text-destructive mt-1 text-[11px] leading-tight" title={priceError.detail}>
                        {priceError.text}
                    </p>
                )}
            </div>
        );
    };

    const qtyControl = (line: T) => renderQtyControl?.(line) ?? <QuantityStepper qty={line.qty} onChange={(delta) => onQtyChange(line, delta)} />;

    const discountControl = (line: T) => (
        <Input
            type="number"
            min={0}
            max={getMaxDiscount(line)}
            value={line.discountPct}
            onChange={(e) => onDiscountChange(line, Number(e.target.value))}
            className={cn(CONTROL_HEIGHT, 'text-center')}
        />
    );

    const removeControl = (line: T) => (
        <Button
            type="button"
            size="icon"
            variant="ghost"
            className={cn('text-muted-foreground hover:text-destructive w-11 md:w-7', CONTROL_HEIGHT)}
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
                                {/* الرؤوس مُحاذاة للأعلى فتقف مسمّياتها الخمسة على سطر واحد،
                                    ويتدلّى تنويه «شامل الضريبة» تحت «السعر» بلا أن يزيحه. */}
                                <TableRow className="bg-muted/40 hover:bg-muted/40">
                                    <TableHead className="h-9 pt-2 text-right align-top">{itemLabel}</TableHead>
                                    <TableHead className="h-9 w-[8rem] pt-2 text-center align-top">الكمية</TableHead>
                                    {/* الأسعار المُدخلة شاملة للضريبة — التنويه هنا لأن هذا هو الحقل الذي يكتب فيه الموظف. */}
                                    <TableHead className="h-9 w-[7rem] pt-2 text-center align-top leading-tight">
                                        السعر
                                        <span className="text-muted-foreground block text-[10px] font-normal">شامل الضريبة</span>
                                    </TableHead>
                                    <TableHead className="h-9 w-[6rem] pt-2 text-center align-top">خصم %</TableHead>
                                    <TableHead className="h-9 w-[7rem] pt-2 text-center align-top">الإجمالي</TableHead>
                                    <TableHead className="h-9 w-[3rem]" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {lines.map((line) => {
                                    const details = renderLineDetails?.(line);

                                    return (
                                        <Fragment key={line.key}>
                                            {/* كل خلايا الصفّ مُحاذاة للأعلى: عناصر التحكّم بارتفاع
                                                واحد (CONTROL_HEIGHT) فتقف على خط أفقي واحد، وما يتدلّى
                                                تحت السعر من تنويه أو خطأ لا يزيح جاريه (تاسك 61). */}
                                            <TableRow className={cn(details && 'border-b-0 hover:bg-transparent')}>
                                                <TableCell className="p-2 align-top">{nameControl(line)}</TableCell>

                                                <TableCell className="p-2 text-center align-top">{qtyControl(line)}</TableCell>

                                                <TableCell className="p-2 text-center align-top">{priceControl(line)}</TableCell>

                                                <TableCell className="p-2 text-center align-top">{discountControl(line)}</TableCell>

                                                <TableCell className="p-2 text-center align-top text-sm font-semibold tabular-nums">
                                                    <span className={cn('flex items-center justify-center', CONTROL_HEIGHT)}>
                                                        {formatCurrency(getLineTotal(line))}
                                                    </span>
                                                </TableCell>

                                                <TableCell className="p-2 text-center align-top">{removeControl(line)}</TableCell>
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
                                        <LineField label="الكمية">{qtyControl(line)}</LineField>
                                        <LineField label="السعر (شامل الضريبة)">{priceControl(line)}</LineField>
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
