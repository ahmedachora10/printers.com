import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { cn, formatSar } from '@/lib/utils';
import { type BreadcrumbItem, type SharedData } from '@/types';
import {
    type CatalogueBranchOption,
    type PublicCategory,
    type PublicPrice,
    type PublicSubcategory,
} from '@/types/catalogue';
import { Head, router, usePage } from '@inertiajs/react';
import { ChevronDown, Printer, Search, Tags } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'قائمة الأسعار', href: '/services/price-list' }];

/**
 * The page lives inside the app shell, so printing has to strip the shell.
 * Everything is hidden, the price-list region alone is made visible again and
 * pulled to the top of the sheet so the paper starts with the list itself.
 */
const PRINT_CSS = `
@media print {
    body * { visibility: hidden !important; }
    #price-list-print, #price-list-print * { visibility: visible !important; }
    #price-list-print {
        position: absolute;
        top: 0;
        inset-inline-start: 0;
        width: 100%;
        padding: 0;
    }
    .no-print { display: none !important; }
}
`;

interface Props {
    categories: PublicCategory[];
    /** Super admin only — everyone else reads their own branch's prices (تاسك 47). */
    branches: CatalogueBranchOption[] | null;
    selectedBranchId: number | null;
}

/** Sentinel for "general prices" — a Select cannot carry a null value. */
const GENERAL = 'general';

export default function ServicePriceList({ categories, branches, selectedBranchId }: Props) {
    const { auth } = usePage<SharedData>().props;
    const [search, setSearch] = useState('');
    const [collapsed, setCollapsed] = useState<Set<number>>(new Set());

    const term = search.trim().toLowerCase();

    // Instant, client-side filtering: a price row survives when the term hits
    // its own name, its subcategory or its category, so searching a category
    // name keeps the whole branch of the tree visible.
    const filtered = useMemo<PublicCategory[]>(() => {
        if (!term) return categories;

        return categories
            .map((category) => {
                const categoryHit = category.nameAr.toLowerCase().includes(term);

                const subcategories = category.subcategories
                    .map((sub) => {
                        const subHit = categoryHit || sub.nameAr.toLowerCase().includes(term);

                        return {
                            ...sub,
                            prices: subHit ? sub.prices : sub.prices.filter((p) => p.name.toLowerCase().includes(term)),
                        };
                    })
                    .filter((sub) => sub.prices.length > 0);

                return { ...category, subcategories };
            })
            .filter((category) => category.subcategories.length > 0);
    }, [categories, term]);

    const totalPrices = useMemo(
        () => filtered.reduce((sum, c) => sum + c.subcategories.reduce((s, sub) => s + sub.prices.length, 0), 0),
        [filtered],
    );

    // The sheet must name the list it prints: staff print their own branch,
    // the super admin prints whichever one they picked.
    const listOwner = branches
        ? (branches.find((b) => b.id === selectedBranchId)?.name ?? 'الأسعار العامة')
        : (auth.branch?.name ?? 'مركز الناسخ للطباعة');

    function toggleCategory(id: number) {
        setCollapsed((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="قائمة أسعار الخدمات" />
            <style>{PRINT_CSS}</style>

            <div className="flex flex-col gap-4 p-4">
                {/* ── Toolbar ───────────────────────────────────────────────── */}
                <div className="no-print flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-2">
                        <Tags className="size-5 text-primary" />
                        <div>
                            <h1 className="text-lg font-bold">قائمة أسعار الخدمات</h1>
                            <p className="text-sm text-muted-foreground">
                                {totalPrices} بند سعري — للاطّلاع فقط
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        {/* The super admin belongs to no branch, so they pick the
                            list they want to read; staff never see this. */}
                        {branches && (
                            <Select
                                value={selectedBranchId === null ? GENERAL : String(selectedBranchId)}
                                onValueChange={(value) =>
                                    router.get(
                                        '/services/price-list',
                                        value === GENERAL ? {} : { branch: value },
                                        { preserveState: false, replace: true },
                                    )
                                }
                            >
                                <SelectTrigger className="w-44">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={GENERAL}>الأسعار العامة</SelectItem>
                                    {branches.map((branch) => (
                                        <SelectItem key={branch.id} value={String(branch.id)}>
                                            {branch.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        <div className="relative w-full sm:w-64">
                            <Search className="pointer-events-none absolute end-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="ابحث عن خدمة..."
                                className="pe-9"
                            />
                        </div>
                        <Button variant="outline" onClick={() => window.print()}>
                            <Printer className="size-4" />
                            طباعة
                        </Button>
                    </div>
                </div>

                {/* ── Printable region ──────────────────────────────────────── */}
                <div id="price-list-print" className="space-y-4">
                    {/* Paper header — only ever rendered on the printed sheet. */}
                    <div className="hidden print:mb-4 print:block print:text-center">
                        <h2 className="text-lg font-bold">{listOwner}</h2>
                        <p className="text-sm">قائمة أسعار الخدمات</p>
                    </div>

                    {filtered.length === 0 ? (
                        <Card className="p-10 text-center text-muted-foreground">
                            {term ? 'لا توجد نتائج مطابقة لبحثك.' : 'لا توجد أسعار متاحة حالياً.'}
                        </Card>
                    ) : (
                        filtered.map((category) => (
                            <CategorySection
                                key={category.id}
                                category={category}
                                // While searching every match stays open, so results
                                // are never hidden behind a collapsed header.
                                open={term !== '' || !collapsed.has(category.id)}
                                onToggle={() => toggleCategory(category.id)}
                            />
                        ))
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

function CategorySection({
    category,
    open,
    onToggle,
}: {
    category: PublicCategory;
    open: boolean;
    onToggle: () => void;
}) {
    const priceCount = category.subcategories.reduce((sum, sub) => sum + sub.prices.length, 0);

    return (
        <Card className="overflow-hidden py-0">
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                className="flex w-full items-center justify-between gap-3 bg-muted/40 px-4 py-3 text-start transition-colors hover:bg-muted/60 print:bg-transparent"
            >
                <span className="flex items-center gap-2 font-semibold">
                    {category.nameAr}
                    <span className="text-xs font-normal text-muted-foreground">({priceCount})</span>
                </span>
                <ChevronDown
                    className={cn('no-print size-4 text-muted-foreground transition-transform', !open && 'rotate-90')}
                />
            </button>

            {/* Kept mounted while collapsed so a print always carries the full
                (filtered) list, whatever is folded away on screen. */}
            <div className={cn('divide-y', !open && 'hidden print:block')}>
                {category.subcategories.map((sub) => (
                    <SubcategoryBlock key={sub.id} subcategory={sub} />
                ))}
            </div>
        </Card>
    );
}

function SubcategoryBlock({ subcategory }: { subcategory: PublicSubcategory }) {
    return (
        <div className="px-4 py-3">
            <h3 className="mb-2 text-sm font-semibold text-primary">{subcategory.nameAr}</h3>

            {/* Desktop (and paper): table. */}
            <div className="hidden sm:block print:block">
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead className="text-start text-[13px] font-semibold text-muted-foreground">الخدمة</TableHead>
                            <TableHead className="text-start text-[13px] font-semibold text-muted-foreground">السعر الأساسي</TableHead>
                            <TableHead className="text-start text-[13px] font-semibold text-muted-foreground">النطاق</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {subcategory.prices.map((price) => (
                            <TableRow key={price.id} className="hover:bg-muted/30">
                                <TableCell className="py-2.5 text-sm font-medium">
                                    {price.name}
                                    {price.isBranchPrice && (
                                        <span className="ms-2 rounded bg-primary/10 px-1.5 py-0.5 text-[11px] font-normal text-primary print:hidden">
                                            سعر الفرع
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="py-2.5 text-sm font-semibold">{formatSar(price.basePrice)}</TableCell>
                                <TableCell className="py-2.5 text-sm text-muted-foreground">
                                    <PriceRange price={price} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            {/* Mobile: one card per price row. */}
            <div className="space-y-2 sm:hidden print:hidden">
                {subcategory.prices.map((price) => (
                    <div key={price.id} className="rounded-lg border p-3">
                        <p className="text-sm font-medium">{price.name}</p>
                        <div className="mt-1 flex items-baseline justify-between gap-2">
                            <span className="text-sm font-semibold text-primary">{formatSar(price.basePrice)}</span>
                            <span className="text-xs text-muted-foreground">
                                <PriceRange price={price} />
                            </span>
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

/** The min–max band, shown only when the two ends actually differ. */
function PriceRange({ price }: { price: PublicPrice }) {
    if (price.minPrice === price.maxPrice) {
        return <span>—</span>;
    }

    return (
        <span>
            {formatSar(price.minPrice)} – {formatSar(price.maxPrice)}
        </span>
    );
}
