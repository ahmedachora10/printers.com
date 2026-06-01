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
import { AlertTriangle, ArrowLeftRight, History, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import inventory from '@/routes/inventory';
import { formatCurrency } from '@/lib/utils';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'المستودع', href: inventory.products.index().url },
    { title: 'المنتجات', href: inventory.products.index().url },
];

interface Category {
    id: number;
    name: string;
}

interface Props {
    items: PaginatedProduct;
    lowStockCount: number;
    categories: Category[];
    units: ProductUnit[];
    filters: {
        search?: string;
        category_id?: string;
        status?: string;
    };
}


export default function ProductsIndex({ items, lowStockCount, categories, units, filters }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Product | null>(null);
    const [deleting, setDeleting] = useState<Product | null>(null);
    const [adjusting, setAdjusting] = useState<Product | null>(null);

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
                cell: (item) => <span className="font-medium">{item.name}</span>,
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
                        {item.currentStock}
                    </span>
                ),
            },
            {
                key: 'minStockLevel',
                header: 'الحد الأدنى',
                cell: (item) => item.minStockLevel,
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
    });
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            router.get(
                inventory.products.index().url,
                {
                    ...(value && { search: value }),
                    ...(filterValues.category_id && { category_id: filterValues.category_id }),
                    ...(filterValues.status && { status: filterValues.status }),
                },
                { preserveState: true, replace: true },
            );
        }, 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        router.get(
            inventory.products.index().url,
            {
                ...(search && { search }),
                ...(next.category_id && { category_id: next.category_id }),
                ...(next.status && { status: next.status }),
            },
            { preserveState: true, replace: true },
        );
    };

    const handleClearAll = () => {
        setSearch('');
        setFilterValues({ category_id: '', status: '' });
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get(inventory.products.index().url, {}, { preserveState: true, replace: true });
    };

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
                        ]}
                        filterValues={filterValues}
                        onFilterChange={handleFilterChange}
                        onClearAll={handleClearAll}
                        actions={
                            <Button size="sm" onClick={openCreate}>
                                <Plus className="size-4" /> إضافة منتج
                            </Button>
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
                        router.get(inventory.products.index({ query: { page } }).url, {}, { preserveState: true, preserveScroll: true });
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
