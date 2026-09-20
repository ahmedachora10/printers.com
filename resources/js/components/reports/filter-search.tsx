import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type UseReportFilters } from '@/hooks/use-report-filters';
import { cn } from '@/lib/utils';
import { Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/**
 * صندوق البحث المرافق لشريط التواريخ: يطبّق نفسه بعد وقفةٍ قصيرة، بخلاف بقيّة
 * الفلاتر التي تنتظر زرّ «تطبيق» في النافذة. يحمل حالته بنفسه ويعيد مزامنتها مع
 * القيمة المطبَّقة، فـ«مسح الكل» يفرّغه بلا سطرٍ في الصفحة.
 */
export default function FilterSearch({ filters, value, placeholder }: { filters: UseReportFilters; value: string; placeholder: string }) {
    const [draft, setDraft] = useState(value);
    const timeout = useRef<ReturnType<typeof setTimeout>>(null);

    useEffect(() => setDraft(value), [value]);

    function change(next: string) {
        setDraft(next);
        if (timeout.current) clearTimeout(timeout.current);
        timeout.current = setTimeout(() => filters.replace('search', next), 400);
    }

    return (
        <div className="space-y-1">
            <Label htmlFor="filter-search" className="text-muted-foreground text-xs">
                بحث
            </Label>
            <div className="relative">
                <Search className="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                <Input
                    id="filter-search"
                    value={draft}
                    onChange={(e) => change(e.target.value)}
                    placeholder={placeholder}
                    className={cn('h-8 w-full ps-9 pe-8 text-sm sm:w-72', draft && 'border-primary/40 bg-primary/5')}
                />
                {draft && (
                    <button
                        type="button"
                        onClick={() => change('')}
                        className="text-muted-foreground hover:text-foreground absolute end-2.5 top-1/2 -translate-y-1/2 rounded p-0.5 transition-colors"
                        aria-label="مسح البحث"
                    >
                        <X className="size-3.5" />
                    </button>
                )}
            </div>
        </div>
    );
}
