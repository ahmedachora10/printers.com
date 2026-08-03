import { router } from '@inertiajs/react';
import { useState } from 'react';

export type FilterValues = Record<string, string>;

export interface UseReportFilters {
    /** Working copy edited inside the modal; committed only on apply. */
    draft: FilterValues;
    setField: (key: string, value: string) => void;
    /** Modal open state and a change handler that re-seeds the draft on open. */
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Commit the draft to the URL and close the modal. */
    apply: () => void;
    /** Clear every filter, navigate, and close the modal. */
    reset: () => void;
    /** Drop a single applied filter (used by the removable chips). */
    remove: (key: string) => void;
    /** Set a single applied filter to a new value and navigate — for chips that narrow rather than clear. */
    replace: (key: string, value: string) => void;
    /** Set several applied filters at once and navigate — for the always-visible date bar. */
    replaceMany: (values: FilterValues) => void;
    /** Whether a given key currently differs from its default. */
    isActive: (key: string) => boolean;
    /** Number of applied filters that differ from their defaults. */
    activeCount: number;
    /** The applied filters as a query object (empty/default keys dropped) — for export links. */
    appliedQuery: FilterValues;
}

/**
 * Shared mechanics for the report filter modal: draft-vs-applied state, query
 * building (dropping empty/default values), and navigation. UI-agnostic — the
 * page still renders its own filter fields as children of <FilterModal>.
 *
 * @param url       report index route
 * @param applied   the currently applied values (from server props, defaulted)
 * @param defaults  the "cleared" value for each key ('' for dates, 'all' for selects)
 */
export function useReportFilters(url: string, applied: FilterValues, defaults: FilterValues): UseReportFilters {
    const [draft, setDraft] = useState<FilterValues>(applied);
    const [open, setOpen] = useState(false);

    const setField = (key: string, value: string) => setDraft((d) => ({ ...d, [key]: value }));

    const buildQuery = (values: FilterValues): FilterValues => {
        const params: FilterValues = {};
        for (const key of Object.keys(defaults)) {
            const value = values[key];
            if (value !== undefined && value !== '' && value !== defaults[key]) {
                params[key] = value;
            }
        }
        return params;
    };

    const navigate = (values: FilterValues) => router.get(url, buildQuery(values), { preserveState: true, preserveScroll: true, replace: true });

    const onOpenChange = (next: boolean) => {
        if (next) setDraft(applied);
        setOpen(next);
    };

    const apply = () => {
        navigate(draft);
        setOpen(false);
    };

    const reset = () => {
        setDraft({ ...defaults });
        router.get(url, {}, { preserveScroll: true, replace: true });
        setOpen(false);
    };

    const replace = (key: string, value: string) => navigate({ ...applied, [key]: value });

    const replaceMany = (values: FilterValues) => navigate({ ...applied, ...values });

    const remove = (key: string) => replace(key, defaults[key]);

    const isActive = (key: string) => {
        const value = applied[key];
        return value !== undefined && value !== '' && value !== defaults[key];
    };

    const activeCount = Object.keys(defaults).filter(isActive).length;

    return {
        draft,
        setField,
        open,
        onOpenChange,
        apply,
        reset,
        remove,
        replace,
        replaceMany,
        isActive,
        activeCount,
        appliedQuery: buildQuery(applied),
    };
}
