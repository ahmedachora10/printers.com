import ProductFormModal from '@/components/products/product-form-modal';
import { DataTable, type ColumnDef } from '@/components/data-table';
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
import { router } from '@inertiajs/react';
import { AlertTriangle, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'المستودع', href: '/inventory/products' },
    { title: 'المنتجات', href: '/inventory/products' },
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

const formatSAR = (amount: number) =>
    new Intl.NumberFormat('ar-SA', { style: 'currency', currency: 'SAR' }).format(amount);

export default function ProductsIndex({ items, lowStockCount, categories, units, filters }: Props) {
    const [formOpen, setFormOpen]   = useState(false);
    const [editing, setEditing]     = useState<Product | null>(null);
    const [deleting, setDeleting]   = useState<Product | null>(null);

    function openCreate() {
        setEditing(null);
        setFormOpen(true);
    }

    function openEdit(item: Product) {
        setEditing(item);
        setFormOpen(true);
    }

    function handleToggleStatus(item: Product) {
        router.patch(`/inventory/products/${item.id}/toggle-status`, {}, { preserveScroll: true });
    }

    function handleDelete() {
        if (!deleting) return;
        router.delete(`/inventory/products/${deleting.id}`, {
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
                        {formatSAR(item.costPrice)}
                    </span>
                ),
            },
            {
                key: 'sellingPrice',
                header: 'سعر البيع',
                cell: (item) => (
                    <span dir="ltr" className="tabular-nums">
                        {formatSAR(item.sellingPrice)}
                    </span>
                ),
            },
            {
                key: 'valuation',
                header: 'التقييم',
                cell: (item) => (
                    <span dir="ltr" className="tabular-nums text-muted-foreground">
                        {formatSAR(item.valuation)}
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

    const [search, setSearch]             = useState(filters.search ?? '');
    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        category_id: filters.category_id ?? '',
        status:      filters.status ?? '',
    });
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            router.get(
                '/inventory/products',
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
            '/inventory/products',
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
        router.get('/inventory/products', {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">المنتجات</h1>
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
                    defaultPageSize={15}
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
        </AppLayout>
    );
}
