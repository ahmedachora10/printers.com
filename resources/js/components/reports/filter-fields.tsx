import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { Check, X } from 'lucide-react';
import * as React from 'react';

/** Labeled wrapper matching the report filter field layout. */
export function FilterField({
    label,
    htmlFor,
    children,
    className,
}: {
    label: string;
    htmlFor?: string;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <div className={cn('space-y-1.5', className)}>
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
        </div>
    );
}

/** من تاريخ / إلى تاريخ pair. */
export function DateRangeFields({
    from,
    to,
    onFromChange,
    onToChange,
    fromLabel = 'من تاريخ',
    toLabel = 'إلى تاريخ',
}: {
    from: string;
    to: string;
    onFromChange: (value: string) => void;
    onToChange: (value: string) => void;
    fromLabel?: string;
    toLabel?: string;
}) {
    return (
        <>
            <FilterField label={fromLabel} htmlFor="filter-from">
                <Input id="filter-from" type="date" value={from} onChange={(e) => onFromChange(e.target.value)} />
            </FilterField>
            <FilterField label={toLabel} htmlFor="filter-to">
                <Input id="filter-to" type="date" value={to} onChange={(e) => onToChange(e.target.value)} />
            </FilterField>
        </>
    );
}

export interface FilterOption {
    value: string;
    label: string;
}

/**
 * Labeled select with a leading "all" option. Used for branch, employee, type
 * and status filters. `value` is the raw string; the "all" sentinel is `all`.
 */
export function FilterSelect({
    label,
    value,
    onChange,
    options,
    allLabel = 'الكل',
    placeholder,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: FilterOption[];
    allLabel?: string;
    placeholder?: string;
}) {
    return (
        <FilterField label={label}>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger>
                    <SelectValue placeholder={placeholder ?? allLabel} />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">{allLabel}</SelectItem>
                    {options.map((option) => (
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </FilterField>
    );
}

/**
 * Labeled searchable multi-select. `value` is a comma-separated id list so it
 * fits the string-based useReportFilters state; an empty string means "all".
 *
 * The list is rendered inline rather than in a popover on purpose: these fields
 * live inside the filter Dialog, and a portalled popover sits outside the
 * dialog's interaction layer — its items become unclickable and the click is
 * read as an outside-press that closes the dialog.
 */
export function FilterMultiSelect({
    label,
    value,
    onChange,
    options,
    allLabel = 'الكل',
    searchPlaceholder = 'بحث...',
    emptyText = 'لا توجد نتائج',
    className,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: FilterOption[];
    allLabel?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    className?: string;
}) {
    const values = value ? value.split(',') : [];
    const selected = options.filter((option) => values.includes(option.value));

    const toggle = (target: string) =>
        onChange((values.includes(target) ? values.filter((v) => v !== target) : [...values, target]).join(','));

    return (
        <FilterField label={label} className={className}>
            <div className="rounded-md border">
                <div className="flex items-center justify-between gap-2 border-b px-2 py-1">
                    <span className="text-muted-foreground text-xs">{selected.length > 0 ? `${selected.length} محدد` : allLabel}</span>
                    {selected.length > 0 && (
                        <Button type="button" variant="ghost" size="sm" className="h-6 px-2 text-xs" onClick={() => onChange('')}>
                            مسح
                        </Button>
                    )}
                </div>

                {selected.length > 0 && (
                    <div className="flex flex-wrap gap-1 border-b p-2">
                        {selected.map((option) => (
                            <Badge key={option.value} variant="secondary" className="gap-1 ps-1 font-normal">
                                <button type="button" onClick={() => toggle(option.value)} aria-label={`إزالة ${option.label}`}>
                                    <X className="size-3" />
                                </button>
                                {option.label}
                            </Badge>
                        ))}
                    </div>
                )}

                <Command>
                    <CommandInput placeholder={searchPlaceholder} />
                    <CommandList className="max-h-40">
                        <CommandEmpty>{emptyText}</CommandEmpty>
                        <CommandGroup>
                            {options.map((option) => {
                                const checked = values.includes(option.value);
                                return (
                                    <CommandItem
                                        key={option.value}
                                        /* Value carries the id too so duplicate names stay distinct to cmdk. */
                                        value={`${option.label} ${option.value}`}
                                        onSelect={() => toggle(option.value)}
                                        className="cursor-pointer"
                                    >
                                        <div
                                            className={cn(
                                                'flex size-4 items-center justify-center rounded-sm border',
                                                checked ? 'border-primary bg-primary text-primary-foreground' : 'border-input',
                                            )}
                                        >
                                            {checked && <Check className="size-3" />}
                                        </div>
                                        {option.label}
                                    </CommandItem>
                                );
                            })}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </div>
        </FilterField>
    );
}
