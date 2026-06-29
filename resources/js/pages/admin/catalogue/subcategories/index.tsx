import categoryRoutes from '@/actions/App/Http/Controllers/CatalogCategoryController';
import priceRoutes from '@/actions/App/Http/Controllers/CatalogPriceController';
import subcategoryRoutes from '@/actions/App/Http/Controllers/CatalogSubcategoryController';
import SubcategoryFormModal from '@/components/catalogue/subcategory-form-modal';
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
import { type CatalogCategory, type CatalogSubcategory, type Paginated } from '@/types/catalogue';
import { Link, router } from '@inertiajs/react';
import { ImageIcon, Pencil, Plus, Tags, Trash2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

interface Props {
    category: CatalogCategory;
    subcategories: Paginated<CatalogSubcategory>;
    filters: { search?: string; status?: string };
}

export default function CatalogSubcategoriesIndex({ category, subcategories, filters }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'دليل الخدمات', href: '/admin/catalogue' },
        { title: category.nameAr, href: subcategoryRoutes.index.url(category.id) },
    ];

    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<CatalogSubcategory | null>(null);
    const [deleting, setDeleting] = useState<CatalogSubcategory | null>(null);

    function openCreate() {
        setEditing(null);
        setFormOpen(true);
    }

    function openEdit(sub: CatalogSubcategory) {
        setEditing(sub);
        setFormOpen(true);
    }

    function handleToggle(sub: CatalogSubcategory) {
        router.patch(subcategoryRoutes.toggleStatus.url(sub), {}, { preserveScroll: true });
    }

    function handleDelete() {
        if (!deleting) return;
        router.delete(subcategoryRoutes.destroy.url(deleting), {
            preserveScroll: true,
            onFinish: () => setDeleting(null),
        });
    }

    const columns = useMemo<ColumnDef<CatalogSubcategory>[]>(
        () => [
            {
                key: 'image',
                header: '',
                headerClassName: 'w-16',
                cell: (s) =>
                    s.imageUrl ? (
                        <img src={s.imageUrl} alt={s.nameAr} className="size-10 rounded-md border bg-white object-contain p-0.5" />
                    ) : (
                        <div className="flex size-10 items-center justify-center rounded-md border bg-muted/40 text-muted-foreground">
                            <ImageIcon className="size-4" />
                        </div>
                    ),
            },
            { key: 'nameAr', header: 'الاسم', cell: (s) => <span className="font-medium">{s.nameAr}</span> },
            {
                key: 'pricesCount',
                header: 'بنود الأسعار',
                cell: (s) => <span className="text-muted-foreground">{s.pricesCount ?? 0}</span>,
            },
            { key: 'sortOrder', header: 'الترتيب', cell: (s) => <span className="text-muted-foreground">{s.sortOrder}</span> },
            {
                key: 'isActive',
                header: 'الحالة',
                cell: (s) => (
                    <button onClick={() => handleToggle(s)} className="cursor-pointer">
                        {s.isActive ? (
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
                headerClassName: 'w-40',
                cell: (s) => (
                    <div className="flex items-center justify-end gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={priceRoutes.index.url(s.id)}>
                                <Tags className="h-3.5 w-3.5" /> الأسعار
                            </Link>
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => openEdit(s)}>
                            <Pencil className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={() => setDeleting(s)}
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
    const [filterValues, setFilterValues] = useState<Record<string, string>>({ status: filters.status ?? '' });
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    const listUrl = subcategoryRoutes.index.url(category.id);

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            router.get(
                listUrl,
                { ...(value && { search: value }), ...(filterValues.status && { status: filterValues.status }) },
                { preserveState: true, replace: true },
            );
        }, 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        router.get(
            listUrl,
            { ...(search && { search }), ...(next.status && { status: next.status }) },
            { preserveState: true, replace: true },
        );
    };

    const handleClearAll = () => {
        setSearch('');
        setFilterValues({ status: '' });
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get(listUrl, {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">الخدمات الفرعية</h1>
                        <p className="text-sm text-muted-foreground">الفئة: {category.nameAr}</p>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={categoryRoutes.index.url()}>العودة للفئات</Link>
                    </Button>
                </div>

                <div className="mb-6">
                    <FilterBar
                        searchable
                        searchPlaceholder="بحث في الخدمات الفرعية..."
                        searchValue={search}
                        onSearchChange={handleSearchChange}
                        filters={[
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
                                <Plus className="size-4" /> إضافة خدمة فرعية
                            </Button>
                        }
                    />
                </div>

                <DataTable columns={columns} data={subcategories.data} keyExtractor={(s) => s.id} />

                <TablePagination
                    currentPage={subcategories.meta.current_page as number}
                    totalPages={subcategories.meta.last_page as number}
                    totalItems={subcategories.meta.total as number}
                    onPageChange={(page) => router.reload({ data: { page } })}
                />
            </div>

            <Dialog open={!!deleting} onOpenChange={(open) => !open && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>
                            هل أنت متأكد من حذف "{deleting?.nameAr}"؟ سيتم حذف كل بنود الأسعار المرتبطة بها.
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

            <SubcategoryFormModal
                key={editing?.id ?? 'create'}
                open={formOpen}
                onOpenChange={setFormOpen}
                categoryId={category.id}
                subcategory={editing ?? undefined}
            />
        </AppLayout>
    );
}
