import { Badge } from '@/components/ui/badge';
import { X } from 'lucide-react';

export interface FilterChip {
    key: string;
    label: string;
    onRemove: () => void;
}

/**
 * Row of removable chips summarizing the applied filters. Renders nothing when
 * no filters are active.
 */
export function ActiveFilterChips({ chips }: { chips: FilterChip[] }) {
    if (chips.length === 0) {
        return null;
    }

    return (
        <div className="mb-6 flex flex-wrap items-center gap-2">
            {chips.map((chip) => (
                <Badge key={chip.key} variant="secondary" className="gap-1 py-1 ps-2.5 pe-1">
                    {chip.label}
                    <button
                        type="button"
                        onClick={chip.onRemove}
                        aria-label={`إزالة ${chip.label}`}
                        className="text-muted-foreground hover:bg-muted-foreground/20 hover:text-foreground rounded-full p-0.5 transition-colors"
                    >
                        <X className="size-3" />
                    </button>
                </Badge>
            ))}
        </div>
    );
}
