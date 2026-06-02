import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatCurrency } from '@/lib/utils';
import { FileText, Minus, Plus, Trash2 } from 'lucide-react';
import { type ReactNode } from 'react';

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
}

function QuantityStepper({ qty, onChange }: { qty: number; onChange: (delta: number) => void }) {
    return (
        <div className="inline-flex items-center rounded-md border">
            <Button type="button" size="icon" variant="ghost" className="size-7 rounded-none" onClick={() => onChange(-1)}>
                <Minus className="size-3" />
            </Button>
            <span className="w-8 text-center text-sm font-medium tabular-nums">{qty}</span>
            <Button type="button" size="icon" variant="ghost" className="size-7 rounded-none" onClick={() => onChange(1)}>
                <Plus className="size-3" />
            </Button>
        </div>
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
}: PosCartTableProps<T>) {
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
                    <div className="overflow-hidden rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow className="hover:bg-transparent">
                                    <TableHead className="text-right">{itemLabel}</TableHead>
                                    <TableHead className="w-[8rem] text-center">الكمية</TableHead>
                                    <TableHead className="w-[7rem] text-center">السعر</TableHead>
                                    <TableHead className="w-[6rem] text-center">خصم %</TableHead>
                                    <TableHead className="w-[7rem] text-center">الإجمالي</TableHead>
                                    <TableHead className="w-[3rem]" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {lines.map((line) => (
                                    <TableRow key={line.key}>
                                        <TableCell className="p-2 align-top">
                                            {isLineSelectable(line) ? (
                                                renderLineSelect(line)
                                            ) : (
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-medium">{line.name}</p>
                                                    <p className="text-muted-foreground text-xs">{renderLineMeta(line)}</p>
                                                </div>
                                            )}
                                        </TableCell>

                                        <TableCell className="p-2 text-center">
                                            <QuantityStepper qty={line.qty} onChange={(delta) => onQtyChange(line, delta)} />
                                        </TableCell>

                                        <TableCell className="p-2 text-center">
                                            {isPriceEditable(line) ? (
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    step="0.01"
                                                    value={line.unitPrice}
                                                    onChange={(e) => onPriceChange(line, Math.max(0, Number(e.target.value) || 0))}
                                                    className="h-8 text-center"
                                                />
                                            ) : (
                                                <span className="text-sm tabular-nums">{formatCurrency(line.unitPrice)}</span>
                                            )}
                                        </TableCell>

                                        <TableCell className="p-2 text-center">
                                            <Input
                                                type="number"
                                                min={0}
                                                max={getMaxDiscount(line)}
                                                value={line.discountPct}
                                                onChange={(e) => onDiscountChange(line, Number(e.target.value))}
                                                className="h-8 text-center"
                                            />
                                        </TableCell>

                                        <TableCell className="p-2 text-center text-sm font-semibold tabular-nums">
                                            {formatCurrency(getLineTotal(line))}
                                        </TableCell>

                                        <TableCell className="p-2 text-center">
                                            <Button
                                                type="button"
                                                size="icon"
                                                variant="ghost"
                                                className="text-muted-foreground hover:text-destructive size-7"
                                                onClick={() => onRemove(line.key)}
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    <Button type="button" variant="outline" size="sm" className="w-full" onClick={onAddManual}>
                        <Plus className="size-4" /> سطر يدوي
                    </Button>
                </>
            )}
        </div>
    );
}
