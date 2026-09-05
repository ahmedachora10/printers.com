import { ChevronDown, ChevronLeft, ChevronRight, ChevronUp, ChevronsUpDown, Inbox } from 'lucide-react';
import * as React from 'react';
import { Fragment, useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';

// ─── Types ──────────────────────────────────────────────────────────────────

export interface ColumnDef<T> {
    key: string;
    header: React.ReactNode;
    cell?: (row: T, index: number) => React.ReactNode;
    sortable?: boolean;
    className?: string;
    headerClassName?: string;
    /** Drop the column from the small-screen card layout — for figures that only make sense in a grid. */
    hideOnMobile?: boolean;
    /** Render this column as the card's heading instead of a labelled field. Defaults to the first column. */
    mobilePrimary?: boolean;
}

export interface TablePaginationProps {
    currentPage: number;
    totalPages: number;
    totalItems: number;
    /**
     * تاسك 78: مدى الصفحة كما حسبه الخادم (`meta.from` و`meta.to`). يُفضَّل على
     * أي حساب هنا: الكنترولرات تصفّح بـ8 و12 و15 و20، فأي حجمٍ مفترض في الواجهة
     * يكذب على كل شاشةٍ لا تصفّح به — وهو ما أنتج «عرض 11‑16 من أصل 16» في صفحةٍ
     * تحمل صفّاً واحداً.
     */
    from?: number | null;
    to?: number | null;
    /** احتياطيٌّ لمن لا يمرّر from/to — ويبقى تخميناً ما لم يُمرَّر معه حجمُ الصفحة الفعلي. */
    pageSize?: number;
    onPageChange: (page: number) => void;
    className?: string;
}

export interface DataTableProps<T extends object> {
    columns: ColumnDef<T>[];
    data: T[];
    keyExtractor: (row: T) => string | number;
    selectable?: boolean;
    emptyState?: React.ReactNode;
    loading?: boolean;
    onSelectionChange?: (selectedKeys: Array<string | number>, selectedRows: T[]) => void;
    caption?: string;
    className?: string;
    /** Footer content, rendered inside a <TableFooter>. Provide <TableRow>…</TableRow>. */
    footer?: React.ReactNode;
    /** When provided, rows become expandable; returns the content shown in a full-width sub-row. */
    renderSubRow?: (row: T) => React.ReactNode;
    /** Extra classes per row — for emphasising subtotal/total rows. */
    rowClassName?: (row: T, index: number) => string | undefined;
    /**
     * Below `md` every row is restated as a labelled card, so a wide table never
     * forces the page to scroll sideways on a phone. Opt out only where the grid
     * itself carries the meaning (a matrix, a count sheet).
     */
    mobileCards?: boolean;
}

interface SortState {
    key: string;
    direction: 'asc' | 'desc';
}

const SKELETON_ROWS = 5;

// ─── Sort Icon ───────────────────────────────────────────────────────────────

function SortIcon({ colKey, sort }: { colKey: string; sort: SortState | null }) {
    if (sort?.key === colKey) {
        return sort.direction === 'asc' ? (
            <ChevronUp className="text-primary ms-1 size-3.5" />
        ) : (
            <ChevronDown className="text-primary ms-1 size-3.5" />
        );
    }
    return <ChevronsUpDown className="text-muted-foreground/40 group-hover:text-muted-foreground ms-1 size-3.5" />;
}

// ─── TablePagination ─────────────────────────────────────────────────────────

export function TablePagination({ currentPage, totalPages, totalItems, from, to, pageSize = 10, onPageChange, className }: TablePaginationProps) {
    const pageNumbers = useMemo<(number | '...')[]>(() => {
        const pages = Array.from({ length: totalPages }, (_, i) => i + 1).filter(
            (p) => p === 1 || p === totalPages || Math.abs(p - currentPage) <= 1,
        );
        return pages.reduce<(number | '...')[]>((acc, p, i, arr) => {
            if (i > 0 && p - (arr[i - 1] as number) > 1) acc.push('...');
            acc.push(p);
            return acc;
        }, []);
    }, [totalPages, currentPage]);

    // الأرقام المرسلة تُعرض كما هي؛ وحين لا تصل يُحسب المدى بالحجم الاحتياطي.
    const firstItem = from ?? (currentPage - 1) * pageSize + 1;
    const lastItem = to ?? Math.min(currentPage * pageSize, totalItems);
    const rangeLabel = totalItems === 0 ? 'لا توجد نتائج' : `عرض ${firstItem}‑${lastItem} من أصل ${totalItems}`;

    return (
        <div
            className={cn(
                'border-border bg-muted/20 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t px-4 py-3 sm:px-5',
                className,
            )}
        >
            <span className="text-muted-foreground text-[13px]">{rangeLabel}</span>

            {totalPages > 1 && (
                <div className="flex flex-wrap items-center gap-1">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => onPageChange(Math.max(1, currentPage - 1))}
                        disabled={currentPage === 1}
                        className="size-11 p-0 md:size-8"
                        aria-label="الصفحة السابقة"
                    >
                        <ChevronRight className="size-4" />
                    </Button>

                    {pageNumbers.map((p, i) =>
                        p === '...' ? (
                            <span key={`ellipsis-${i}`} className="text-muted-foreground w-8 text-center text-[13px]">
                                &hellip;
                            </span>
                        ) : (
                            <Button
                                key={p}
                                variant={currentPage === p ? 'default' : 'ghost'}
                                size="sm"
                                onClick={() => onPageChange(p as number)}
                                className={cn(
                                    'size-11 p-0 text-[13px] md:size-8',
                                    currentPage === p && 'bg-primary text-primary-foreground hover:bg-primary/90',
                                )}
                            >
                                {p}
                            </Button>
                        ),
                    )}

                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => onPageChange(Math.min(totalPages, currentPage + 1))}
                        disabled={currentPage === totalPages}
                        className="size-11 p-0 md:size-8"
                        aria-label="الصفحة التالية"
                    >
                        <ChevronLeft className="size-4" />
                    </Button>
                </div>
            )}
        </div>
    );
}

// ─── DataTable ───────────────────────────────────────────────────────────────

export function DataTable<T extends object>({
    columns,
    data,
    keyExtractor,
    selectable = false,
    emptyState,
    loading = false,
    onSelectionChange,
    caption,
    className,
    footer,
    renderSubRow,
    rowClassName,
    mobileCards = true,
}: DataTableProps<T>) {
    const [sort, setSort] = useState<SortState | null>(null);
    const [selectedKeys, setSelectedKeys] = useState<Set<string | number>>(new Set());
    const [expandedKeys, setExpandedKeys] = useState<Set<string | number>>(new Set());
    const expandable = !!renderSubRow;

    const toggleExpand = (key: string | number) => {
        setExpandedKeys((prev) => {
            const next = new Set(prev);
            if (next.has(key)) next.delete(key);
            else next.add(key);
            return next;
        });
    };

    // ── Sort ──────────────────────────────────────────────────────────────────
    const sorted = useMemo(() => {
        if (!sort) return data;
        return [...data].sort((a, b) => {
            const av = String((a as Record<string, unknown>)[sort.key] ?? '');
            const bv = String((b as Record<string, unknown>)[sort.key] ?? '');
            const cmp = av < bv ? -1 : av > bv ? 1 : 0;
            return sort.direction === 'asc' ? cmp : -cmp;
        });
    }, [data, sort]);

    // ── Handlers ──────────────────────────────────────────────────────────────
    const handleSort = (key: string) => {
        setSort((prev) => {
            if (prev?.key === key) return prev.direction === 'asc' ? { key, direction: 'desc' } : null;
            return { key, direction: 'asc' };
        });
    };

    const allPageSelected = sorted.length > 0 && sorted.every((r) => selectedKeys.has(keyExtractor(r)));
    const somePageSelected = sorted.some((r) => selectedKeys.has(keyExtractor(r)));

    const toggleSelectAll = () => {
        const pageKeys = sorted.map(keyExtractor);
        const next = new Set(selectedKeys);
        if (allPageSelected) {
            pageKeys.forEach((k) => next.delete(k));
        } else {
            pageKeys.forEach((k) => next.add(k));
        }
        setSelectedKeys(next);
        onSelectionChange?.(
            [...next],
            data.filter((r) => next.has(keyExtractor(r))),
        );
    };

    const toggleRow = (key: string | number) => {
        const next = new Set(selectedKeys);
        if (next.has(key)) {
            next.delete(key);
        } else {
            next.add(key);
        }
        setSelectedKeys(next);
        onSelectionChange?.(
            [...next],
            data.filter((r) => next.has(keyExtractor(r))),
        );
    };

    const colSpan = columns.length + (selectable ? 1 : 0) + (expandable ? 1 : 0);

    // ── Card layout (below md) ────────────────────────────────────────────────
    const cardColumns = columns.filter((col) => !col.hideOnMobile);
    const primaryColumn = cardColumns.find((col) => col.mobilePrimary) ?? cardColumns[0];
    const cellValue = (col: ColumnDef<T>, row: T, idx: number) =>
        col.cell ? col.cell(row, idx) : String((row as Record<string, unknown>)[col.key] ?? '');

    const cards = (
        <div className="divide-border/60 divide-y md:hidden">
            {loading ? (
                Array.from({ length: SKELETON_ROWS }).map((_, i) => (
                    <div key={i} className="space-y-3 p-4">
                        <Skeleton className="h-4 w-1/2" />
                        <Skeleton className="h-3 w-3/4" />
                        <Skeleton className="h-3 w-2/3" />
                    </div>
                ))
            ) : sorted.length === 0 ? (
                <div className="flex h-40 items-center justify-center px-4 text-center">
                    {emptyState ?? (
                        <div className="text-muted-foreground flex flex-col items-center gap-2">
                            <Inbox className="size-8 opacity-40" />
                            <span className="text-sm">لا توجد بيانات</span>
                        </div>
                    )}
                </div>
            ) : (
                sorted.map((row, idx) => {
                    const key = keyExtractor(row);
                    const selected = selectedKeys.has(key);
                    const isExpanded = expandable && expandedKeys.has(key);

                    return (
                        <div key={key} className={cn('p-4', selected && 'bg-primary/5', rowClassName?.(row, idx))}>
                            <div className="flex items-start gap-3">
                                {selectable && (
                                    <Checkbox
                                        className="mt-0.5 size-5"
                                        checked={selected}
                                        onCheckedChange={() => toggleRow(key)}
                                        aria-label={`تحديد الصف ${idx + 1}`}
                                    />
                                )}
                                {primaryColumn && <div className="min-w-0 flex-1 text-sm font-semibold">{cellValue(primaryColumn, row, idx)}</div>}
                                {expandable && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="-mt-1 size-11 shrink-0"
                                        onClick={() => toggleExpand(key)}
                                        aria-expanded={isExpanded}
                                        aria-label="عرض التفاصيل"
                                    >
                                        <ChevronDown className={cn('size-4 transition-transform', isExpanded && 'rotate-180')} />
                                    </Button>
                                )}
                            </div>

                            <dl className="mt-2 space-y-1.5">
                                {cardColumns.map((col) => {
                                    if (col === primaryColumn) return null;
                                    const value = cellValue(col, row, idx);
                                    // A header-less column (row actions) gets the full width and no label.
                                    if (!col.header) {
                                        return (
                                            <div key={col.key} className="pt-1.5">
                                                {value}
                                            </div>
                                        );
                                    }
                                    return (
                                        <div key={col.key} className="flex items-start justify-between gap-3 text-sm">
                                            <dt className="text-muted-foreground shrink-0 text-[13px]">{col.header}</dt>
                                            <dd className="min-w-0 text-end">{value}</dd>
                                        </div>
                                    );
                                })}
                            </dl>

                            {isExpanded && <div className="bg-muted/30 -mx-4 mt-3 -mb-4 overflow-x-auto">{renderSubRow!(row)}</div>}
                        </div>
                    );
                })
            )}

            {/* The totals row keeps its grid — it is the one place columns must line up. */}
            {footer && !loading && sorted.length > 0 && (
                <Table>
                    <TableFooter>{footer}</TableFooter>
                </Table>
            )}
        </div>
    );

    return (
        <Card className={cn('flex flex-col overflow-hidden rounded-md', className)}>
            {mobileCards && cards}

            {/* ── Table ─────────────────────────────────────────────────────── */}
            <div className={cn(mobileCards && 'hidden md:block')}>
                <Table>
                    <TableHeader className="bg-muted/50">
                        <TableRow className="border-border border-b hover:bg-transparent">
                            {selectable && (
                                <TableHead className="w-10 px-4">
                                    <Checkbox
                                        checked={allPageSelected ? true : somePageSelected ? 'indeterminate' : false}
                                        onCheckedChange={toggleSelectAll}
                                        aria-label="تحديد الكل"
                                    />
                                </TableHead>
                            )}
                            {expandable && <TableHead className="w-8 px-4" />}
                            {columns.map((col) => (
                                <TableHead
                                    key={col.key}
                                    onClick={col.sortable ? () => handleSort(col.key) : undefined}
                                    className={cn(
                                        'text-muted-foreground px-4 text-start text-[13px] font-semibold whitespace-nowrap',
                                        col.sortable && 'group cursor-pointer select-none',
                                        col.headerClassName,
                                    )}
                                >
                                    <span className="inline-flex items-center">
                                        {col.header}
                                        {col.sortable && <SortIcon colKey={col.key} sort={sort} />}
                                    </span>
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        {loading ? (
                            /* ── Skeleton ───────────────────────────────────────── */
                            Array.from({ length: SKELETON_ROWS }).map((_, i) => (
                                <TableRow key={i} className="hover:bg-transparent">
                                    {selectable && (
                                        <TableCell className="w-10 px-4">
                                            <Skeleton className="size-4" />
                                        </TableCell>
                                    )}
                                    {columns.map((col) => (
                                        <TableCell key={col.key} className={col.className}>
                                            <Skeleton className="h-4 w-3/4" />
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))
                        ) : sorted.length === 0 ? (
                            /* ── Empty state ────────────────────────────────────── */
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={colSpan} className="h-40 text-center">
                                    {emptyState ?? (
                                        <div className="text-muted-foreground flex flex-col items-center gap-2">
                                            <Inbox className="size-8 opacity-40" />
                                            <span className="text-sm">لا توجد بيانات</span>
                                        </div>
                                    )}
                                </TableCell>
                            </TableRow>
                        ) : (
                            /* ── Rows ───────────────────────────────────────────── */
                            sorted.map((row, idx) => {
                                const key = keyExtractor(row);
                                const selected = selectedKeys.has(key);
                                const isExpanded = expandable && expandedKeys.has(key);
                                return (
                                    <Fragment key={key}>
                                        <TableRow
                                            data-state={selected ? 'selected' : undefined}
                                            onClick={expandable ? () => toggleExpand(key) : undefined}
                                            className={cn(
                                                'border-border/50 hover:bg-muted/30 border-b transition-colors',
                                                selected && 'bg-primary/5 hover:bg-primary/[0.08]',
                                                expandable && 'cursor-pointer',
                                                rowClassName?.(row, idx),
                                            )}
                                        >
                                            {selectable && (
                                                <TableCell className="w-10 px-4" onClick={(e) => e.stopPropagation()}>
                                                    <Checkbox
                                                        checked={selected}
                                                        onCheckedChange={() => toggleRow(key)}
                                                        aria-label={`تحديد الصف ${idx + 1}`}
                                                    />
                                                </TableCell>
                                            )}
                                            {expandable && (
                                                <TableCell className="w-8 px-4">
                                                    {isExpanded ? (
                                                        <ChevronDown className="text-muted-foreground size-4" />
                                                    ) : (
                                                        <ChevronLeft className="text-muted-foreground size-4" />
                                                    )}
                                                </TableCell>
                                            )}
                                            {columns.map((col) => (
                                                <TableCell key={col.key} className={cn('px-4 py-3.5 text-sm', col.className)}>
                                                    {col.cell ? col.cell(row, idx) : String((row as Record<string, unknown>)[col.key] ?? '')}
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                        {isExpanded && (
                                            <TableRow className="bg-muted/30 hover:bg-muted/30">
                                                <TableCell colSpan={colSpan} className="p-0">
                                                    {renderSubRow!(row)}
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    </Fragment>
                                );
                            })
                        )}
                    </TableBody>

                    {footer && !loading && sorted.length > 0 && <TableFooter>{footer}</TableFooter>}
                </Table>
            </div>

            {/* ── Caption ───────────────────────────────────────────────────── */}
            {caption && <p className="border-border text-muted-foreground border-t px-4 py-2 text-[12px]">{caption}</p>}
        </Card>
    );
}
