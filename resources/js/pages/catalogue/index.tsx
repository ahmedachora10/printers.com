import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn, formatSar } from '@/lib/utils';
import { type CatalogueBranchOption, type PublicCategory } from '@/types/catalogue';
import { Head, router } from '@inertiajs/react';
import { ImageIcon, MessageCircle, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

interface Props {
    categories: PublicCategory[];
    whatsappNumber: string | null;
    branches: CatalogueBranchOption[];
    selectedBranchId: number | null;
}

/** Sentinel for "general prices" — a Select cannot carry a null value. */
const GENERAL = 'general';

export default function PublicCatalogue({ categories, whatsappNumber, branches, selectedBranchId }: Props) {
    const [activeCategoryId, setActiveCategoryId] = useState<number | null>(categories[0]?.id ?? null);
    const [activeSubId, setActiveSubId] = useState<number | null>(categories[0]?.subcategories[0]?.id ?? null);
    const [search, setSearch] = useState('');

    const activeCategory = useMemo(
        () => categories.find((c) => c.id === activeCategoryId) ?? null,
        [categories, activeCategoryId],
    );

    // When searching, flatten all matching price rows across the whole catalogue.
    const searchResults = useMemo(() => {
        const term = search.trim().toLowerCase();
        if (!term) return null;

        return categories
            .map((cat) => ({
                category: cat,
                subs: cat.subcategories
                    .map((sub) => ({
                        sub,
                        prices: sub.prices.filter(
                            (p) =>
                                p.name.toLowerCase().includes(term) ||
                                sub.nameAr.toLowerCase().includes(term) ||
                                cat.nameAr.toLowerCase().includes(term),
                        ),
                    }))
                    .filter((entry) => entry.prices.length > 0),
            }))
            .filter((entry) => entry.subs.length > 0);
    }, [categories, search]);

    const activeSub = useMemo(
        () => activeCategory?.subcategories.find((s) => s.id === activeSubId) ?? activeCategory?.subcategories[0] ?? null,
        [activeCategory, activeSubId],
    );

    function selectCategory(id: number) {
        setActiveCategoryId(id);
        const cat = categories.find((c) => c.id === id);
        setActiveSubId(cat?.subcategories[0]?.id ?? null);
    }

    const waHref = whatsappNumber
        ? `https://wa.me/${whatsappNumber.replace(/[^0-9]/g, '')}`
        : null;

    return (
        <div dir="rtl" className="min-h-screen bg-muted/30">
            <Head title="دليل الخدمات والأسعار" />

            {/* Fixed header */}
            <header className="sticky top-0 z-30 border-b bg-background/95 backdrop-blur">
                <div className="mx-auto flex max-w-5xl flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-xl font-bold sm:text-2xl">مركز الناسخ للطباعة</h1>
                        <p className="text-sm text-muted-foreground">دليل الخدمات والأسعار</p>
                    </div>
                    <div className="flex w-full items-center gap-2 sm:w-auto">
                        {/* تاسك 47: each branch prices its own list, so the visitor
                            says which one they are asking about. Until they do,
                            the general price list is what is shown. */}
                        {branches.length > 1 && (
                            <Select
                                value={selectedBranchId === null ? GENERAL : String(selectedBranchId)}
                                onValueChange={(value) =>
                                    router.get('/catalogue', value === GENERAL ? {} : { branch: value }, {
                                        preserveState: false,
                                        replace: true,
                                    })
                                }
                            >
                                <SelectTrigger className="w-40 shrink-0">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={GENERAL}>كل الفروع</SelectItem>
                                    {branches.map((branch) => (
                                        <SelectItem key={branch.id} value={String(branch.id)}>
                                            {branch.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        <div className="relative w-full sm:w-72">
                            <Search className="pointer-events-none absolute end-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="ابحث عن خدمة..."
                                className="pe-9"
                            />
                        </div>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-5xl px-4 py-6">
                {searchResults ? (
                    /* ---- Search mode: flat results across the catalogue ---- */
                    searchResults.length === 0 ? (
                        <p className="py-16 text-center text-muted-foreground">لا توجد نتائج مطابقة لبحثك.</p>
                    ) : (
                        <div className="space-y-6">
                            {searchResults.map(({ category, subs }) => (
                                <section key={category.id}>
                                    <h2 className="mb-2 text-lg font-bold">{category.nameAr}</h2>
                                    <div className="space-y-4">
                                        {subs.map(({ sub, prices }) => (
                                            <div key={sub.id} className="rounded-xl border bg-background p-4">
                                                <h3 className="mb-3 font-semibold text-primary">{sub.nameAr}</h3>
                                                <PriceList prices={prices} />
                                            </div>
                                        ))}
                                    </div>
                                </section>
                            ))}
                        </div>
                    )
                ) : categories.length === 0 ? (
                    <p className="py-16 text-center text-muted-foreground">لا توجد خدمات متاحة حالياً.</p>
                ) : (
                    <>
                        {/* Category tabs */}
                        <div className="mb-4 flex flex-wrap gap-2">
                            {categories.map((cat) => (
                                <button
                                    key={cat.id}
                                    onClick={() => selectCategory(cat.id)}
                                    className={cn(
                                        'flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-medium transition-colors',
                                        cat.id === activeCategoryId
                                            ? 'border-primary bg-primary text-primary-foreground'
                                            : 'border-border bg-background hover:bg-muted',
                                    )}
                                >
                                    {cat.imageUrl && (
                                        <img src={cat.imageUrl} alt="" className="size-5 rounded-full object-cover" />
                                    )}
                                    {cat.nameAr}
                                </button>
                            ))}
                        </div>

                        {/* Subcategory tabs */}
                        {activeCategory && activeCategory.subcategories.length > 0 && (
                            <div className="mb-6 flex flex-wrap gap-2 border-b pb-3">
                                {activeCategory.subcategories.map((sub) => (
                                    <button
                                        key={sub.id}
                                        onClick={() => setActiveSubId(sub.id)}
                                        className={cn(
                                            'rounded-lg px-3 py-1.5 text-sm transition-colors',
                                            sub.id === (activeSub?.id ?? null)
                                                ? 'bg-primary/10 font-semibold text-primary'
                                                : 'text-muted-foreground hover:bg-muted',
                                        )}
                                    >
                                        {sub.nameAr}
                                    </button>
                                ))}
                            </div>
                        )}

                        {/* Price list */}
                        {activeSub ? (
                            <div className="rounded-xl border bg-background p-4 sm:p-6">
                                <div className="mb-4 flex items-center gap-3">
                                    {activeSub.imageUrl ? (
                                        <img
                                            src={activeSub.imageUrl}
                                            alt={activeSub.nameAr}
                                            className="size-12 rounded-lg border bg-white object-contain p-1"
                                        />
                                    ) : (
                                        <div className="flex size-12 items-center justify-center rounded-lg border bg-muted/40 text-muted-foreground">
                                            <ImageIcon className="size-5" />
                                        </div>
                                    )}
                                    <h2 className="text-lg font-bold">{activeSub.nameAr}</h2>
                                </div>
                                <PriceList prices={activeSub.prices} />
                            </div>
                        ) : (
                            <p className="py-12 text-center text-muted-foreground">لا توجد خدمات فرعية في هذه الفئة.</p>
                        )}
                    </>
                )}
            </main>

            {/* Floating WhatsApp button */}
            {waHref && (
                <a
                    href={waHref}
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="تواصل عبر واتساب"
                    className="fixed bottom-6 start-6 z-40 flex size-14 items-center justify-center rounded-full bg-green-500 text-white shadow-lg transition-transform hover:scale-105"
                >
                    <MessageCircle className="size-7" />
                </a>
            )}
        </div>
    );
}

function PriceList({ prices }: { prices: { id: number; name: string; minPrice: number; maxPrice: number; basePrice: number }[] }) {
    if (prices.length === 0) {
        return <p className="text-sm text-muted-foreground">لا توجد أسعار.</p>;
    }

    return (
        <ul className="divide-y">
            {prices.map((price) => (
                <li key={price.id} className="group flex items-center justify-between gap-4 py-3">
                    <span className="font-medium">{price.name}</span>
                    <div className="text-end">
                        <span className="font-semibold text-primary">
                            {price.minPrice === price.maxPrice
                                ? formatSar(price.minPrice)
                                : `${formatSar(price.minPrice)} – ${formatSar(price.maxPrice)}`}
                        </span>
                        <span className="block text-xs text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100">
                            السعر الأساسي: {formatSar(price.basePrice)}
                        </span>
                    </div>
                </li>
            ))}
        </ul>
    );
}
