import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import * as React from 'react';

/** Labeled wrapper matching the report filter field layout. */
export function FilterField({ label, htmlFor, children }: { label: string; htmlFor?: string; children: React.ReactNode }) {
    return (
        <div className="space-y-1.5">
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
