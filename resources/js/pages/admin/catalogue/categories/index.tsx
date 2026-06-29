import categoryRoutes from '@/actions/App/Http/Controllers/CatalogCategoryController';
import subcategoryRoutes from '@/actions/App/Http/Controllers/CatalogSubcategoryController';
import CategoryFormModal from '@/components/catalogue/category-form-modal';
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
import { type CatalogCategory, type Paginated } from '@/types/catalogue';
import { Link, router } from '@inertiajs/react';
import { ImageIcon, Layers, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'دليل الخدمات', href: '/admin/catalogue' }];

interface Props {
    categories: Paginated<CatalogCategory>;
    filters: { search?: string; status?: string };
}

export default function CatalogCategoriesIndex({ categories, filters }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<CatalogCategory | null>(null);
    const [deleting, setDeleting] = useState<CatalogCategory | null>(null);

    function openCreate() {
        setEditing(null);
        setFormOpen(true);
    }

    function openEdit(category: CatalogCategory) {
        setEditing(category);
        setFormOpen(true);
    }

    function handleToggle(category: CatalogCategory) {
        router.patch(categoryRoutes.toggleStatus.url(category), {}, { preserveScroll: true });
    }

    function handleDelete() {
        if (!deleting) return;
        router.delete(categoryRoutes.destroy.url(deleting), {
            preserveScroll: true,
            onFinish: () => setDeleting(null),
        });
    }

    const columns = useMemo<ColumnDef<CatalogCategory>[]>(
        () => [
            {
                key: 'image',
                header: '',
                headerClassName: 'w-16',
                cell: (c) =>
                    c.imageUrl ? (
                        <img src={c.imageUrl} alt={c.nameAr} className="size-10 rounded-md border bg-white object-contain p-0.5" />
                    ) : (
                        <div className="flex size-10 items-center justify-center rounded-md border bg-muted/40 text-muted-foreground">
                            <ImageIcon className="size-4" />
                        </div>
                    ),
            },
            {
                key: 'nameAr',
                header: 'اسم الفئة',
                cell: (c) => <span className="font-medium">{c.nameAr}</span>,
            },
            {
                key: 'subcategoriesCount',
                header: 'الخدمات الفرعية',
                cell: (c) => <span className="text-muted-foreground">{c.subcategoriesCount ?? 0}</span>,
            },
            {
                key: 'sortOrder',
                header: 'الترتيب',
                cell: (c) => <span className="text-muted-foreground">{c.sortOrder}</span>,
            },
            {
                key: 'isActive',
                header: 'الحالة',
                cell: (c) => (
                    <button onClick={() => handleToggle(c)} className="cursor-pointer">
                        {c.isActive ? (
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
                cell: (c) => (
                    <div className="flex items-center justify-end gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={subcategoryRoutes.index.url(c.id)}>
                                <Layers className="h-3.5 w-3.5" /> الخدمات
                            </Link>
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => openEdit(c)}>
                            <Pencil className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={() => setDeleting(c)}
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

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            router.get(
                categoryRoutes.index.url(),
                { ...(value && { search: value }), ...(filterValues.status && { status: filterValues.status }) },
                { preserveState: true, replace: true },
            );
        }, 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        router.get(
            categoryRoutes.index.url(),
            { ...(search && { search }), ...(next.status && { status: next.status }) },
            { preserveState: true, replace: true },
        );
    };

    const handleClearAll = () => {
        setSearch('');
        setFilterValues({ status: '' });
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get(categoryRoutes.index.url(), {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">دليل الخدمات — الفئات</h1>
                </div>

                <div className="mb-6">
                    <FilterBar
                        searchable
                        searchPlaceholder="بحث في الفئات..."
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
                                <Plus className="size-4" /> إضافة فئة
                            </Button>
                        }
                    />
                </div>

                <DataTable columns={columns} data={categories.data} keyExtractor={(c) => c.id} />

                <TablePagination
                    currentPage={categories.meta.current_page as number}
                    totalPages={categories.meta.last_page as number}
                    totalItems={categories.meta.total as number}
                    onPageChange={(page) => router.reload({ data: { page } })}
                />
            </div>

            <Dialog open={!!deleting} onOpenChange={(open) => !open && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>
                            هل أنت متأكد من حذف فئة "{deleting?.nameAr}"؟ سيتم حذف كل الخدمات الفرعية والأسعار المرتبطة بها.
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

            <CategoryFormModal
                key={editing?.id ?? 'create'}
                open={formOpen}
                onOpenChange={setFormOpen}
                category={editing ?? undefined}
            />
        </AppLayout>
    );
}
