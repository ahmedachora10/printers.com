import * as React from 'react';
import { useState, useMemo } from 'react';
import { ChevronDown, ChevronLeft, ChevronRight, ChevronUp, ChevronsUpDown, Inbox } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';

// ─── Types ──────────────────────────────────────────────────────────────────

export interface ColumnDef<T> {
    key: string;
    header: React.ReactNode;
    cell?: (row: T, index: number) => React.ReactNode;
    sortable?: boolean;
    className?: string;
    headerClassName?: string;
}

export interface TablePaginationProps {
    currentPage: number;
    totalPages: number;
    totalItems: number;
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
            <ChevronUp className="ms-1 size-3.5 text-primary" />
        ) : (
            <ChevronDown className="ms-1 size-3.5 text-primary" />
        );
    }
    return <ChevronsUpDown className="ms-1 size-3.5 text-muted-foreground/40 group-hover:text-muted-foreground" />;
}

// ─── TablePagination ─────────────────────────────────────────────────────────

export function TablePagination({ currentPage, totalPages, totalItems, pageSize = 10, onPageChange, className }: TablePaginationProps) {
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

    return (
        <div className={cn('flex items-center justify-between border-t border-border bg-muted/20 px-5 py-3', className)}>
            <span className="text-[13px] text-muted-foreground">
                عرض {(currentPage - 1) * pageSize + 1}&#x2011;{Math.min(currentPage * pageSize, totalItems)} من أصل {totalItems}
            </span>

            {totalPages > 1 && (
                <div className="flex items-center gap-1">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => onPageChange(Math.max(1, currentPage - 1))}
                        disabled={currentPage === 1}
                        className="h-8 w-8 p-0"
                        aria-label="الصفحة السابقة"
                    >
                        <ChevronRight className="size-4" />
                    </Button>

                    {pageNumbers.map((p, i) =>
                        p === '...' ? (
                            <span key={`ellipsis-${i}`} className="w-8 text-center text-[13px] text-muted-foreground">
                                &hellip;
                            </span>
                        ) : (
                            <Button
                                key={p}
                                variant={currentPage === p ? 'default' : 'ghost'}
                                size="sm"
                                onClick={() => onPageChange(p as number)}
                                className={cn(
                                    'h-8 w-8 p-0 text-[13px]',
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
                        className="h-8 w-8 p-0"
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
}: DataTableProps<T>) {
    const [sort, setSort] = useState<SortState | null>(null);
    const [selectedKeys, setSelectedKeys] = useState<Set<string | number>>(new Set());

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
        onSelectionChange?.([...next], data.filter((r) => next.has(keyExtractor(r))));
    };

    const toggleRow = (key: string | number) => {
        const next = new Set(selectedKeys);
        if (next.has(key)) {
            next.delete(key);
        } else {
            next.add(key);
        }
        setSelectedKeys(next);
        onSelectionChange?.([...next], data.filter((r) => next.has(keyExtractor(r))));
    };

    const colSpan = columns.length + (selectable ? 1 : 0);

    return (
        <Card className={cn('flex flex-col overflow-hidden rounded-md', className)}>
            {/* ── Table ─────────────────────────────────────────────────────── */}
            <Table>
                <TableHeader className="bg-muted/50">
                    <TableRow className="border-b border-border hover:bg-transparent">
                        {selectable && (
                            <TableHead className="w-10 px-4">
                                <Checkbox
                                    checked={allPageSelected ? true : somePageSelected ? 'indeterminate' : false}
                                    onCheckedChange={toggleSelectAll}
                                    aria-label="تحديد الكل"
                                />
                            </TableHead>
                        )}
                        {columns.map((col) => (
                            <TableHead
                                key={col.key}
                                onClick={col.sortable ? () => handleSort(col.key) : undefined}
                                className={cn(
                                    'text-start text-[13px] font-semibold text-muted-foreground whitespace-nowrap px-4',
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
                                    <div className="flex flex-col items-center gap-2 text-muted-foreground">
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
                            return (
                                <TableRow
                                    key={key}
                                    data-state={selected ? 'selected' : undefined}
                                    className={cn(
                                        'border-b border-border/50 transition-colors hover:bg-muted/30',
                                        selected && 'bg-primary/5 hover:bg-primary/[0.08]',
                                    )}
                                >
                                    {selectable && (
                                        <TableCell className="w-10 px-4">
                                            <Checkbox
                                                checked={selected}
                                                onCheckedChange={() => toggleRow(key)}
                                                aria-label={`تحديد الصف ${idx + 1}`}
                                            />
                                        </TableCell>
                                    )}
                                    {columns.map((col) => (
                                        <TableCell key={col.key} className={cn('px-4 py-3.5 text-sm', col.className)}>
                                            {col.cell
                                                ? col.cell(row, idx)
                                                : String((row as Record<string, unknown>)[col.key] ?? '')}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            );
                        })
                    )}
                </TableBody>
            </Table>

            {/* ── Caption ───────────────────────────────────────────────────── */}
            {caption && (
                <p className="border-t border-border px-4 py-2 text-[12px] text-muted-foreground">{caption}</p>
            )}
        </Card>
    );
}
