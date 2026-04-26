import * as React from 'react';
import { useState, useMemo } from 'react';
import { ChevronDown, ChevronLeft, ChevronRight, ChevronUp, ChevronsUpDown, Inbox, Search } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
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

export interface DataTableProps<T extends object> {
    columns: ColumnDef<T>[];
    data: T[];
    keyExtractor: (row: T) => string | number;
    selectable?: boolean;
    searchable?: boolean;
    searchPlaceholder?: string;
    defaultPageSize?: number;
    toolbarStart?: React.ReactNode;
    toolbarEnd?: React.ReactNode;
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

// ─── DataTable ───────────────────────────────────────────────────────────────

export function DataTable<T extends object>({
    columns,
    data,
    keyExtractor,
    selectable = false,
    searchable = false,
    searchPlaceholder = 'بحث...',
    defaultPageSize = 10,
    toolbarStart,
    toolbarEnd,
    emptyState,
    loading = false,
    onSelectionChange,
    caption,
    className,
}: DataTableProps<T>) {
    const [sort, setSort] = useState<SortState | null>(null);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [selectedKeys, setSelectedKeys] = useState<Set<string | number>>(new Set());

    // ── Filter ────────────────────────────────────────────────────────────────
    const filtered = useMemo(() => {
        if (!search.trim()) return data;
        const q = search.toLowerCase();
        return data.filter((row) =>
            Object.values(row as Record<string, unknown>).some((v) =>
                String(v ?? '').toLowerCase().includes(q),
            ),
        );
    }, [data, search]);

    // ── Sort ──────────────────────────────────────────────────────────────────
    const sorted = useMemo(() => {
        if (!sort) return filtered;
        return [...filtered].sort((a, b) => {
            const av = String((a as Record<string, unknown>)[sort.key] ?? '');
            const bv = String((b as Record<string, unknown>)[sort.key] ?? '');
            const cmp = av < bv ? -1 : av > bv ? 1 : 0;
            return sort.direction === 'asc' ? cmp : -cmp;
        });
    }, [filtered, sort]);

    // ── Paginate ──────────────────────────────────────────────────────────────
    const totalPages = Math.max(1, Math.ceil(sorted.length / defaultPageSize));
    const paginated = useMemo(
        () => sorted.slice((page - 1) * defaultPageSize, page * defaultPageSize),
        [sorted, page, defaultPageSize],
    );

    // ── Handlers ──────────────────────────────────────────────────────────────
    const handleSort = (key: string) => {
        setSort((prev) => {
            if (prev?.key === key) return prev.direction === 'asc' ? { key, direction: 'desc' } : null;
            return { key, direction: 'asc' };
        });
        setPage(1);
    };

    const handleSearch = (v: string) => {
        setSearch(v);
        setPage(1);
    };

    const allPageSelected = paginated.length > 0 && paginated.every((r) => selectedKeys.has(keyExtractor(r)));
    const somePageSelected = paginated.some((r) => selectedKeys.has(keyExtractor(r)));

    const toggleSelectAll = () => {
        const pageKeys = paginated.map(keyExtractor);
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
        next.has(key) ? next.delete(key) : next.add(key);
        setSelectedKeys(next);
        onSelectionChange?.([...next], data.filter((r) => next.has(keyExtractor(r))));
    };

    // ── Pagination pages list ─────────────────────────────────────────────────
    const pageNumbers = useMemo<(number | '...')[]>(() => {
        const pages = Array.from({ length: totalPages }, (_, i) => i + 1).filter(
            (p) => p === 1 || p === totalPages || Math.abs(p - page) <= 1,
        );
        return pages.reduce<(number | '...')[]>((acc, p, i, arr) => {
            if (i > 0 && p - (arr[i - 1] as number) > 1) acc.push('...');
            acc.push(p);
            return acc;
        }, []);
    }, [totalPages, page]);

    const hasToolbar = searchable || toolbarStart !== undefined || toolbarEnd !== undefined;
    const colSpan = columns.length + (selectable ? 1 : 0);

    return (
        <div className={cn('flex flex-col overflow-hidden rounded-lg border border-border bg-card', className)}>
            {/* ── Toolbar ───────────────────────────────────────────────────── */}
            {hasToolbar && (
                <div className="flex items-center justify-between gap-3 border-b border-border bg-card px-4 py-3">
                    <div className="flex items-center gap-2">{toolbarStart}</div>
                    <div className="flex items-center gap-2">
                        {searchable && (
                            <div className="relative">
                                <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={(e) => handleSearch(e.target.value)}
                                    placeholder={searchPlaceholder}
                                    className="h-9 w-56 ps-9 text-sm"
                                />
                            </div>
                        )}
                        {toolbarEnd}
                    </div>
                </div>
            )}

            {/* ── Table ─────────────────────────────────────────────────────── */}
            <Table>
                <TableHeader className="bg-muted/60">
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
                                    'text-start text-[13px] font-semibold text-foreground/80 whitespace-nowrap',
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
                                        <div className="size-4 animate-pulse rounded bg-muted" />
                                    </TableCell>
                                )}
                                {columns.map((col) => (
                                    <TableCell key={col.key} className={col.className}>
                                        <div className="h-4 w-3/4 animate-pulse rounded bg-muted" />
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))
                    ) : paginated.length === 0 ? (
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
                        paginated.map((row, idx) => {
                            const key = keyExtractor(row);
                            const selected = selectedKeys.has(key);
                            return (
                                <TableRow
                                    key={key}
                                    data-state={selected ? 'selected' : undefined}
                                    className={cn(
                                        'border-b border-border/60 transition-colors',
                                        selected && 'bg-primary/5 hover:bg-primary/8',
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
                                        <TableCell key={col.key} className={cn('py-3 text-sm', col.className)}>
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

            {/* ── Pagination ────────────────────────────────────────────────── */}
            {totalPages > 1 && (
                <div className="flex items-center justify-between border-t border-border bg-card px-4 py-3">
                    <span className="text-[13px] text-muted-foreground">
                        {sorted.length} نتيجة &mdash; الصفحة {page} من {totalPages}
                    </span>
                    <div className="flex items-center gap-1">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setPage((p) => Math.max(1, p - 1))}
                            disabled={page === 1}
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
                                    variant={page === p ? 'default' : 'ghost'}
                                    size="sm"
                                    onClick={() => setPage(p as number)}
                                    className={cn(
                                        'h-8 w-8 p-0 text-[13px]',
                                        page === p && 'bg-primary text-primary-foreground hover:bg-primary/90',
                                    )}
                                >
                                    {p}
                                </Button>
                            ),
                        )}

                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                            disabled={page === totalPages}
                            className="h-8 w-8 p-0"
                            aria-label="الصفحة التالية"
                        >
                            <ChevronLeft className="size-4" />
                        </Button>
                    </div>
                </div>
            )}

            {/* ── Caption ───────────────────────────────────────────────────── */}
            {caption && (
                <p className="border-t border-border px-4 py-2 text-[12px] text-muted-foreground">{caption}</p>
            )}
        </div>
    );
}
