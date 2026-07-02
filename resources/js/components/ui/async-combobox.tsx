import { Check, ChevronsUpDown, Loader2 } from 'lucide-react';
import * as React from 'react';

import { Button } from '@/components/ui/button';
import { Command, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

export interface AsyncOption<T = unknown> {
    value: string;
    label: string;
    data?: T;
}

export interface AsyncComboboxProps<T> {
    /** Fetches options for a search term. Called (debounced) while open, and once on open with an empty term. */
    fetcher: (query: string) => Promise<AsyncOption<T>[]>;
    /** Currently selected value; use the sentinel value (or '') for "nothing selected". */
    value: string;
    /** Label shown on the trigger for the current value — its option is not kept in the fetched list. */
    selectedLabel?: string;
    onChange: (value: string, option: AsyncOption<T> | null) => void;
    /** Always-present option at the top (e.g. a "walk-in" sentinel) that clears the selection. */
    sentinel?: AsyncOption<T>;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    loadingText?: string;
    className?: string;
    triggerClassName?: string;
    debounceMs?: number;
}

/**
 * Combobox whose options come from a server endpoint instead of a preloaded list.
 * The parent never holds the full dataset — only the fetched page and the chosen row.
 */
export function AsyncCombobox<T>({
    fetcher,
    value,
    selectedLabel,
    onChange,
    sentinel,
    placeholder = 'اختر...',
    searchPlaceholder = 'بحث...',
    emptyText = 'لا توجد نتائج',
    loadingText = 'جارِ البحث...',
    className,
    triggerClassName,
    debounceMs = 500,
}: AsyncComboboxProps<T>) {
    const [open, setOpen] = React.useState(false);
    const [query, setQuery] = React.useState('');
    const [results, setResults] = React.useState<AsyncOption<T>[]>([]);
    const [loading, setLoading] = React.useState(false);
    const reqId = React.useRef(0);

    // Reset the query each time the popover opens so the full initial page shows.
    React.useEffect(() => {
        if (open) setQuery('');
    }, [open]);

    // Debounced fetch; a monotonic request id drops stale responses that resolve out of order.
    React.useEffect(() => {
        if (!open) return;
        const term = query.trim();
        setLoading(true);
        const id = ++reqId.current;
        const handle = setTimeout(() => {
            fetcher(term)
                .then((opts) => {
                    if (id === reqId.current) setResults(opts);
                })
                .catch(() => {
                    if (id === reqId.current) setResults([]);
                })
                .finally(() => {
                    if (id === reqId.current) setLoading(false);
                });
        }, debounceMs);
        return () => clearTimeout(handle);
    }, [open, query, debounceMs, fetcher]);

    const hasSelection = !!value && value !== sentinel?.value;
    const triggerLabel = hasSelection ? (selectedLabel ?? placeholder) : (sentinel?.label ?? placeholder);

    function choose(opt: AsyncOption<T>) {
        onChange(opt.value, opt.data === undefined ? null : opt);
        setOpen(false);
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
                        hasSelection ? 'border-primary/40 bg-primary/5 text-primary' : 'text-muted-foreground',
                        triggerClassName,
                    )}
                >
                    <span className="truncate">{triggerLabel}</span>
                    <ChevronsUpDown className="size-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className={cn('w-56 p-0', className)} align="start">
                {/* Server does the filtering, so disable cmdk's built-in filter. */}
                <Command shouldFilter={false}>
                    <CommandInput placeholder={searchPlaceholder} value={query} onValueChange={setQuery} />
                    <CommandList>
                        {sentinel && (
                            <CommandItem value={`__sentinel__${sentinel.value}`} onSelect={() => choose(sentinel)}>
                                <Check className={cn('size-4', value === sentinel.value ? 'opacity-100' : 'opacity-0')} />
                                {sentinel.label}
                            </CommandItem>
                        )}
                        {loading ? (
                            <div className="text-muted-foreground flex items-center justify-center gap-2 py-6 text-sm">
                                <Loader2 className="size-4 animate-spin" /> {loadingText}
                            </div>
                        ) : results.length === 0 ? (
                            <div className="text-muted-foreground py-6 text-center text-sm">{emptyText}</div>
                        ) : (
                            results.map((opt) => (
                                <CommandItem key={opt.value} value={opt.value} onSelect={() => choose(opt)}>
                                    <Check className={cn('size-4', opt.value === value ? 'opacity-100' : 'opacity-0')} />
                                    {opt.label}
                                </CommandItem>
                            ))
                        )}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
