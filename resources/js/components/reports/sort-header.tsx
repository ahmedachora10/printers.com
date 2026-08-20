import { cn } from '@/lib/utils';
import { ChevronDown, ChevronUp, ChevronsUpDown } from 'lucide-react';

interface Props {
    label: string;
    /** Server-side column name — must be on the controller's allow-list. */
    sortKey: string;
    /** Currently applied sort, from the server. */
    sort: string;
    dir: 'asc' | 'desc';
    onSort: (key: string) => void;
    className?: string;
}

/**
 * Clickable column header driving a **server-side** sort.
 *
 * `DataTable`'s own `sortable` flag sorts the rows it was handed, which on a
 * paginated list would reorder one page in isolation and read as data loss.
 * This header instead re-requests the list, so the whole result set is ordered.
 */
export default function SortHeader({ label, sortKey, sort, dir, onSort, className }: Props) {
    const active = sort === sortKey;

    return (
        <button
            type="button"
            onClick={() => onSort(sortKey)}
            className={cn('group inline-flex cursor-pointer items-center select-none', active && 'text-foreground font-semibold', className)}
            aria-label={`فرز حسب ${label}`}
        >
            {label}
            {active ? (
                dir === 'asc' ? (
                    <ChevronUp className="text-primary ms-1 size-3.5" />
                ) : (
                    <ChevronDown className="text-primary ms-1 size-3.5" />
                )
            ) : (
                <ChevronsUpDown className="text-muted-foreground/40 group-hover:text-muted-foreground ms-1 size-3.5" />
            )}
        </button>
    );
}
