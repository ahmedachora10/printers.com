import ImportDialog from '@/components/import/import-dialog';
import ProductFormModal from '@/components/products/product-form-modal';
import StockAdjustmentModal from '@/components/products/stock-adjustment-modal';
import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { FilterBar } from '@/components/filter-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type PaginatedProduct, type Product } from '@/types/product';
import { type ProductUnit } from '@/types/product-unit';
import { Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowLeftRight, Download, History, Pencil, Plus, Trash2, Upload } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import productRoutes from '@/actions/App/Http/Controllers/ProductController';
import inventory from '@/routes/inventory';
import { formatCurrency, formatQty } from '@/lib/utils';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'المستودع', href: inventory.products.index().url },
    { title: 'المنتجات', href: inventory.products.index().url },
];

interface Category {
    id: number;
    name: string;
}

interface Branch {
    id: number;
    name: string;
}

interface Props {
    items: PaginatedProduct;
    lowStockCount: number;
    categories: Category[];
    units: ProductUnit[];
    /** تاسك 72: فروع السوبر أدمن ليختار نطاق التصدير/الاستيراد — null لمن ثُبِّت فرعه. */
    branches: Branch[] | null;
    ownBranchName?: string | null;
    filters: {
        search?: string;
        category_id?: string;
        status?: string;
        branch?: string;
    };
}


export default function ProductsIndex({ items, lowStockCount, categories, units, branches, ownBranchName, filters }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Product | null>(null);
    const [deleting, setDeleting] = useState<Product | null>(null);
    const [adjusting, setAdjusting] = useState<Product | null>(null);
    const [importOpen, setImportOpen] = useState(false);

    function openCreate() {
        setEditing(null);
        setFormOpen(true);
    }

    function openEdit(item: Product) {
        setEditing(item);
        setFormOpen(true);
    }

    function handleToggleStatus(item: Product) {
        router.patch(inventory.products.toggleStatus(item.id).url, {}, { preserveScroll: true });
    }

    function handleDelete() {
        if (!deleting) return;
        router.delete(inventory.products.destroy(deleting.id).url, {
            onFinish: () => setDeleting(null),
        });
    }

    const columns = useMemo<ColumnDef<Product>[]>(
        () => [
            {
                key: 'sku',
                header: 'SKU',
                sortable: true,
                cell: (item) => (
                    <span className="font-mono text-xs tracking-widest text-muted-foreground">
                        {item.sku}
                    </span>
                ),
            },
            {
                key: 'name',
                header: 'اسم المنتج',
                sortable: true,
                cell: (item) => (
                    <span className="flex items-center gap-1.5">
                        <span className="font-medium">{item.name}</span>
                        {/* منتج بالمتر المربع (تاسك 51) — كمياته وسعره بالمساحة */}
                        {item.isSqm && (
                            <span className="rounded-full border px-1.5 py-0.5 text-[10px] leading-4 font-medium text-sky-700 dark:text-sky-400">
                                م²
                            </span>
                        )}
                    </span>
                ),
            },
            {
                key: 'categoryName',
                header: 'الفئة',
                cell: (item) => item.categoryName ?? '—',
            },
            {
                key: 'currentStock',
                header: 'المخزون الحالي',
                cell: (item) => (
                    <span
                        className={
                            item.currentStock <= item.minStockLevel
                                ? 'font-semibold text-red-600'
                                : 'text-foreground'
                        }
                    >
                        {formatQty(item.currentStock)}
                        {item.isSqm && <span className="text-muted-foreground text-xs"> م²</span>}
                    </span>
                ),
            },
            {
                key: 'minStockLevel',
                header: 'الحد الأدنى',
                cell: (item) => formatQty(item.minStockLevel),
            },
            {
                key: 'costPrice',
                header: 'سعر التكلفة',
                cell: (item) => (
                    <span dir="ltr" className="tabular-nums">
                        {formatCurrency(item.costPrice)}
                    </span>
                ),
            },
            {
                key: 'sellingPrice',
                header: 'سعر البيع',
                cell: (item) => (
                    <span dir="ltr" className="tabular-nums">
                        {formatCurrency(item.sellingPrice)}
                    </span>
                ),
            },
            {
                key: 'isActive',
                header: 'الحالة',
                cell: (item) => (
                    <button onClick={() => handleToggleStatus(item)} className="cursor-pointer">
                        {item.isActive ? (
                            <Badge variant="outline" className="gap-1.5 border-green-200 bg-green-50 text-green-700">
                                <span className="inline-block size-1.5 rounded-full bg-green-500" />
                                نشط
                            </Badge>
                        ) : (
                            <Badge variant="outline" className="gap-1.5 border-border bg-muted/60 text-muted-foreground">
                                <span className="inline-block size-1.5 rounded-full bg-muted-foreground/50" />
                                غير نشط
                            </Badge>
                        )}
                    </button>
                ),
            },
            {
                key: 'actions',
                header: '',
                headerClassName: 'w-24',
                cell: (item) => (
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            title="تسوية المخزون"
                            onClick={() => setAdjusting(item)}
                        >
                            <ArrowLeftRight className="h-3.5 w-3.5" />
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => openEdit(item)}>
                            <Pencil className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={() => setDeleting(item)}
                        >
                            <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                    </div>
                ),
            },
        ],
        [],
    );

    const [search, setSearch] = useState(filters.search ?? '');
    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        category_id: filters.category_id ?? '',
        status: filters.status ?? '',
        branch: filters.branch ?? '',
    });
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    const buildQuery = (values: Record<string, string>, term: string) => ({
        ...(term && { search: term }),
        ...(values.category_id && { category_id: values.category_id }),
        ...(values.status && { status: values.status }),
        ...(values.branch && { branch: values.branch }),
    });

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            router.get(inventory.products.index().url, buildQuery(filterValues, value), {
                preserveState: true,
                replace: true,
            });
        }, 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        router.get(inventory.products.index().url, buildQuery(next, search), {
            preserveState: true,
            replace: true,
        });
    };

    const handleClearAll = () => {
        setSearch('');
        setFilterValues({ category_id: '', status: '', branch: '' });
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get(inventory.products.index().url, {}, { preserveState: true, replace: true });
    };

    // تاسك 72: الاستيراد يهبط على فرعٍ واحد. السوبر أدمن يسمّيه في النافذة —
    // وفلتر الشاشة مجرّد اقتراحٍ افتتاحي — ومدير الفرع يُقال له اسم فرعه.
    const [importBranch, setImportBranch] = useState(filters.branch ?? '');

    // يُصدّر ما تملك: فرع المدير، أو ما يرشّحه فلتر السوبر أدمن (وكل الفروع بلا فلتر).
    const exportUrl = filterValues.branch
        ? `${productRoutes.export.url()}?branch=${filterValues.branch}`
        : productRoutes.export.url();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">المنتجات</h1>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={inventory.stockMovements.index().url}>
                            <History className="size-4" /> تحركات المخزون
                        </Link>
                    </Button>
                </div>

                {lowStockCount > 0 && (
                    <div className="mb-4 flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-amber-800">
                        <AlertTriangle className="size-4 shrink-0" />
                        <span className="text-sm font-medium">
                            ⚠️ {lowStockCount} منتجات وصلت للحد الأدنى
                        </span>
                    </div>
                )}

                <div className="mb-6">
                    <FilterBar
                        searchable
                        searchPlaceholder="بحث بالاسم أو SKU..."
                        searchValue={search}
                        onSearchChange={handleSearchChange}
                        filters={[
                            {
                                key: 'category_id',
                                placeholder: 'الفئة',
                                options: categories.map((c) => ({ value: c.id.toString(), label: c.name })),
                            },
                            {
                                key: 'status',
                                placeholder: 'الحالة',
                                options: [
                                    { value: '1', label: 'نشط' },
                                    { value: '0', label: 'غير نشط' },
                                ],
                            },
                            // فلتر الفرع للسوبر أدمن وحده — من ثُبّت فرعه لا يرى إلا فرعه أصلاً.
                            ...(branches
                                ? [
                                      {
                                          key: 'branch',
                                          placeholder: 'الفرع',
                                          options: branches.map((b) => ({ value: b.id.toString(), label: b.name })),
                                      },
                                  ]
                                : []),
                        ]}
                        filterValues={filterValues}
                        onFilterChange={handleFilterChange}
                        onClearAll={handleClearAll}
                        actions={
                            <>
                                <Button variant="outline" size="sm" onClick={() => setImportOpen(true)}>
                                    <Upload className="size-4" /> استيراد الكل
                                </Button>
                                <Button variant="outline" size="sm" asChild>
                                    <a href={exportUrl}>
                                        <Download className="size-4" /> تصدير الكل
                                    </a>
                                </Button>
                                <Button size="sm" onClick={openCreate}>
                                    <Plus className="size-4" /> إضافة منتج
                                </Button>
                            </>
                        }
                    />
                </div>

                <DataTable
                    columns={columns}
                    data={items.data}
                    keyExtractor={(item) => item.id}
                />

                <TablePagination
                    currentPage={items.meta.current_page as number}
                    totalPages={items.meta.last_page as number}
                    totalItems={items.meta.total as number}
                    onPageChange={(page) => {
                        router.reload({ data: { page } });
                    }}
                />
            </div>

            <Dialog open={!!deleting} onOpenChange={(open) => !open && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>
                            هل أنت متأكد من حذف المنتج "{deleting?.name}"؟ لا يمكن التراجع عن هذا الإجراء.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeleting(null)}>
                            إلغاء
                        </Button>
                        <Button variant="destructive" onClick={handleDelete}>
                            حذف
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <ImportDialog
                open={importOpen}
                onOpenChange={setImportOpen}
                title="استيراد المنتجات"
                description="ملف Excel بأعمدة المنتج. المطابقة بـSKU داخل الفرع: الموجود يُحدّث والجديد يُضاف، ولا يُحذف شيء. وعمود «المخزون الحالي» يُقرأ ولا يُكتب — الرصيد يأتي من حركات المخزون وحدها."
                previewUrl={productRoutes.importPreview.url()}
                commitUrl={productRoutes.import.url()}
                templateUrl={productRoutes.importTemplate.url()}
                payload={{ branch: importBranch }}
                scope={{
                    options: branches ? branches.map((b) => ({ value: String(b.id), label: b.name })) : null,
                    value: importBranch,
                    onChange: setImportBranch,
                    pinnedLabel: ownBranchName ?? 'فرعك',
                    hint: 'كل صفوف الملف ستُنسب إلى هذا الفرع — عمود «الفرع» في ملف التصدير للقراءة فقط.',
                }}
                onImported={() => router.reload()}
            />

            <ProductFormModal
                key={editing?.id ?? 'create'}
                open={formOpen}
                onOpenChange={setFormOpen}
                product={editing ?? undefined}
                categories={categories}
                units={units}
            />

            <StockAdjustmentModal
                key={adjusting?.id ?? 'adjust'}
                open={!!adjusting}
                onOpenChange={(open) => !open && setAdjusting(null)}
                product={adjusting ?? undefined}
            />
        </AppLayout>
    );
}
