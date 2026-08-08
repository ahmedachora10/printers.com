import * as React from 'react';
import { Search, SlidersHorizontal, X } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Combobox, MultiCombobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';

// ─── Types ───────────────────────────────────────────────────────────────────

export interface FilterOption {
    value: string;
    label: string;
}

export interface FilterConfig {
    key: string;
    placeholder: string;
    options: FilterOption[];
    width?: string;
    /** Render a searchable combobox (type-to-filter) instead of a plain select. */
    searchable?: boolean;
    /** Placeholder for the search box inside a searchable combobox. */
    searchPlaceholder?: string;
    /**
     * Allow selecting several values at once. The value lives in `multiValues`
     * (not `filterValues`) and changes fire `onMultiChange` instead of
     * `onFilterChange`.
     */
    multiple?: boolean;
}

export interface FilterBarProps {
    searchable?: boolean;
    searchPlaceholder?: string;
    searchValue?: string;
    onSearchChange?: (value: string) => void;

    filters?: FilterConfig[];
    filterValues?: Record<string, string>;
    onFilterChange?: (key: string, value: string) => void;

    /** Selected values for filters marked `multiple`, keyed by filter key. */
    multiValues?: Record<string, string[]>;
    onMultiChange?: (key: string, values: string[]) => void;

    onClearAll?: () => void;

    actions?: React.ReactNode;
    className?: string;
}

// ─── Active Chip ─────────────────────────────────────────────────────────────

function ActiveChip({ label, onRemove }: { label: string; onRemove: () => void }) {
    return (
        <Badge
            variant="outline"
            className="gap-1 border-primary/20 bg-primary/10 py-1 font-medium text-primary hover:bg-primary/10"
        >
            {label}
            <button
                type="button"
                onClick={onRemove}
                className="ms-0.5 rounded-full p-0.5 transition-colors hover:bg-primary/20"
                aria-label={`إزالة ${label}`}
            >
                <X className="size-3" />
            </button>
        </Badge>
    );
}

// ─── FilterBar ────────────────────────────────────────────────────────────────

export function FilterBar({
    searchable = false,
    searchPlaceholder = 'بحث...',
    searchValue = '',
    onSearchChange,
    filters = [],
    filterValues = {},
    onFilterChange,
    multiValues = {},
    onMultiChange,
    onClearAll,
    actions,
    className,
}: FilterBarProps) {
    const activeFilterChips = filters.filter((f) => !f.multiple && !!filterValues[f.key]);
    // One chip per selected value on multi filters: [{ filter, value }, ...].
    const activeMultiChips = filters
        .filter((f) => f.multiple)
        .flatMap((f) => (multiValues[f.key] ?? []).map((value) => ({ filter: f, value })));
    const hasActiveSearch = searchable && searchValue.trim().length > 0;
    const hasAnyActive = activeFilterChips.length > 0 || activeMultiChips.length > 0 || hasActiveSearch;

    return (
        <Card
            className={cn(
                'flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-md px-4 py-3.5 sm:px-5',
                className,
            )}
        >
            <div className="flex flex-wrap items-center gap-2 w-full">
                {hasAnyActive && (
                    <>
                        {hasActiveSearch && (
                            <ActiveChip
                                label={`"${searchValue}"`}
                                onRemove={() => onSearchChange?.('')}
                            />
                        )}


                        {actions && searchValue.trim().length > 0 && <Separator orientation="vertical" className="h-5" />}

                        {activeFilterChips.map((f) => {
                            const option = f.options.find((o) => o.value === filterValues[f.key]);
                            return (
                                <ActiveChip
                                    key={f.key}
                                    label={`${f.placeholder}: ${option?.label ?? filterValues[f.key]}`}
                                    onRemove={() => onFilterChange?.(f.key, '')}
                                />
                            );
                        })}

                        {activeMultiChips.map(({ filter, value }) => {
                            const option = filter.options.find((o) => o.value === value);
                            return (
                                <ActiveChip
                                    key={`${filter.key}:${value}`}
                                    label={`${filter.placeholder}: ${option?.label ?? value}`}
                                    onRemove={() =>
                                        onMultiChange?.(
                                            filter.key,
                                            (multiValues[filter.key] ?? []).filter((v) => v !== value),
                                        )
                                    }
                                />
                            );
                        })}

                        {(activeFilterChips.length > 0 || activeMultiChips.length > 0) && (
                            <Separator orientation="vertical" className="h-5" />
                        )}

                        <Button
                            type="button"
                            variant="destructive"
                            size="sm"
                            onClick={onClearAll}
                            className="h-7 gap-1 px-2 text-xs text-white hover:text-white"
                        >
                            <X className="size-3" />
                            مسح الكل
                        </Button>
                    </>
                )}
            </div>


            <div className="flex flex-wrap items-center gap-2">

                {searchable && (
                    <div className="relative w-full sm:w-auto">
                        <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={searchValue}
                            onChange={(e) => onSearchChange?.(e.target.value)}
                            placeholder={searchPlaceholder}
                            className={cn(
                                'h-9 w-full ps-9 text-sm sm:w-64',
                                searchValue && 'border-primary/40 bg-primary/5',
                            )}
                        />
                        {searchValue && (
                            <button
                                type="button"
                                onClick={() => onSearchChange?.('')}
                                className="absolute end-2.5 top-1/2 -translate-y-1/2 rounded p-0.5 text-muted-foreground transition-colors hover:text-foreground"
                                aria-label="مسح البحث"
                            >
                                <X className="size-3.5" />
                            </button>
                        )}
                    </div>
                )}

                {filters.map((f) =>
                    f.multiple ? (
                        <MultiCombobox
                            key={f.key}
                            options={f.options}
                            values={multiValues[f.key] ?? []}
                            onChange={(vals) => onMultiChange?.(f.key, vals)}
                            placeholder={f.placeholder}
                            searchable={f.searchable}
                            searchPlaceholder={f.searchPlaceholder ?? `بحث في ${f.placeholder}...`}
                            triggerClassName={f.width ?? 'w-40'}
                        />
                    ) : f.searchable ? (
                        <Combobox
                            key={f.key}
                            options={f.options}
                            value={filterValues[f.key] || ''}
                            onChange={(val) => onFilterChange?.(f.key, val)}
                            placeholder={f.placeholder}
                            searchPlaceholder={f.searchPlaceholder ?? `بحث في ${f.placeholder}...`}
                            triggerClassName={f.width ?? 'w-40'}
                        />
                    ) : (
                    <Select
                        key={f.key}
                        value={filterValues[f.key] || ''}
                        onValueChange={(val) => onFilterChange?.(f.key, val === '__all__' ? '' : val)}
                    >
                        <SelectTrigger
                            className={cn(
                                'h-9 text-sm',
                                filterValues[f.key]
                                    ? 'border-primary/40 bg-primary/5 text-primary'
                                    : 'text-muted-foreground',
                                f.width ?? 'w-36',
                            )}
                        >
                            <SelectValue placeholder={f.placeholder} />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all__">{`كل ${f.placeholder}`}</SelectItem>
                            {f.options.map((opt) => (
                                <SelectItem key={opt.value} value={opt.value}>
                                    {opt.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    ),
                )}


                {filters.length > 0 && (
                    <SlidersHorizontal className="size-4 text-muted-foreground/60" aria-hidden />
                )}
            </div>

            {/* Callers pass whole toolbars in here; let them wrap rather than
                stretch the bar past a narrow viewport. */}
            {actions && <div className="flex min-w-0 flex-wrap items-center gap-2">{actions}</div>}
        </Card>
    );
}
