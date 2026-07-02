import { Check, ChevronsUpDown } from 'lucide-react';
import * as React from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export interface ComboboxOption {
    value: string;
    label: string;
}

export interface ComboboxProps {
    options: ComboboxOption[];
    value?: string;
    onChange?: (value: string) => void;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    className?: string;
    triggerClassName?: string;
}

export function Combobox({
    options,
    value = '',
    onChange,
    placeholder = 'اختر...',
    searchPlaceholder = 'بحث...',
    emptyText = 'لا توجد نتائج',
    className,
    triggerClassName,
}: ComboboxProps) {
    const [open, setOpen] = React.useState(false);
    const selected = options.find((o) => o.value === value);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    className={cn(
                        'h-9 justify-between gap-2 text-sm font-normal',
                        value ? 'border-primary/40 bg-primary/5 text-primary' : 'text-muted-foreground',
                        triggerClassName,
                    )}
                >
                    <span className="truncate">{selected?.label ?? placeholder}</span>
                    <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className={cn('w-56 p-0', className)} align="start">
                <Command>
                    <CommandInput placeholder={searchPlaceholder} />
                    <CommandList>
                        <CommandEmpty>{emptyText}</CommandEmpty>
                        <CommandGroup>
                            {options.map((opt) => (
                                <CommandItem
                                    key={opt.value}
                                    value={opt.label}
                                    onSelect={() => {
                                        onChange?.(opt.value === value ? '' : opt.value);
                                        setOpen(false);
                                    }}
                                >
                                    <Check
                                        className={cn(
                                            'size-4',
                                            opt.value === value ? 'opacity-100' : 'opacity-0',
                                        )}
                                    />
                                    {opt.label}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

export interface MultiComboboxProps {
    options: ComboboxOption[];
    values?: string[];
    onChange?: (values: string[]) => void;
    placeholder?: string;
    /** Show a type-to-filter search box inside the dropdown. */
    searchable?: boolean;
    searchPlaceholder?: string;
    emptyText?: string;
    className?: string;
    triggerClassName?: string;
}

export function MultiCombobox({
    options,
    values = [],
    onChange,
    placeholder = 'اختر...',
    searchable = false,
    searchPlaceholder = 'بحث...',
    emptyText = 'لا توجد نتائج',
    className,
    triggerClassName,
}: MultiComboboxProps) {
    const [open, setOpen] = React.useState(false);

    function toggle(value: string) {
        onChange?.(values.includes(value) ? values.filter((v) => v !== value) : [...values, value]);
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    className={cn(
                        'h-9 justify-between gap-2 text-sm font-normal',
                        values.length > 0 ? 'border-primary/40 bg-primary/5 text-primary' : 'text-muted-foreground',
                        triggerClassName,
                    )}
                >
                    <span className="flex items-center gap-1.5 truncate">
                        {placeholder}
                        {values.length > 0 && (
                            <Badge
                                variant="secondary"
                                className="h-5 min-w-5 justify-center rounded-full px-1.5 text-xs tabular-nums"
                            >
                                {values.length}
                            </Badge>
                        )}
                    </span>
                    <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className={cn('w-56 p-0', className)} align="start">
                <Command>
                    {searchable && <CommandInput placeholder={searchPlaceholder} />}
                    <CommandList>
                        <CommandEmpty>{emptyText}</CommandEmpty>
                        <CommandGroup>
                            {options.map((opt) => {
                                const checked = values.includes(opt.value);
                                return (
                                    <CommandItem key={opt.value} value={opt.label} onSelect={() => toggle(opt.value)}>
                                        <div
                                            className={cn(
                                                'flex size-4 items-center justify-center rounded-sm border',
                                                checked
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : 'border-input',
                                            )}
                                        >
                                            {checked && <Check className="size-3" />}
                                        </div>
                                        {opt.label}
                                    </CommandItem>
                                );
                            })}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
