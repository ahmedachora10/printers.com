import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

/** تاسك 168 — «الاسم» = ترتيب الخادم كما وصل؛ «الأعلى/الأقل» بقيمة العمولة. */
export type AmountSort = 'name' | 'desc' | 'asc';

export function sortByAmount<T>(rows: T[], sort: AmountSort, amount: (row: T) => number): T[] {
    if (sort === 'name') return rows;

    return [...rows].sort((a, b) => (sort === 'desc' ? amount(b) - amount(a) : amount(a) - amount(b)));
}

export function AmountSortSelect({ value, onChange }: { value: AmountSort; onChange: (value: AmountSort) => void }) {
    return (
        <Select value={value} onValueChange={(v) => onChange(v as AmountSort)}>
            <SelectTrigger className="w-44" aria-label="الترتيب">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="name">الترتيب: الاسم</SelectItem>
                <SelectItem value="desc">الأعلى عمولة</SelectItem>
                <SelectItem value="asc">الأقل عمولة</SelectItem>
            </SelectContent>
        </Select>
    );
}
