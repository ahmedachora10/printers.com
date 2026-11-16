import categoryRoutes, { destroy, index, toggleStatus } from '@/actions/App/Http/Controllers/ProductCategoryController';
import { scopeLabel } from '@/components/branch-scope-field';
import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { FilterBar } from '@/components/filter-bar';
import ImportDialog from '@/components/import/import-dialog';
import ProductCategoryFormModal from '@/components/product-categories/product-category-form-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type PaginatedProductCategory, type ProductCategory } from '@/types/product-category';
import { router } from '@inertiajs/react';
import { Download, Pencil, Plus, Trash2, Upload } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'فئات المنتجات', href: '/product-categories' }];

interface Props {
    items: PaginatedProductCategory;
    /** للسوبر أدمن وحده — نطاق الفئة وفلتره (تاسك 237). */
    branches: { id: number; name: string }[] | null;
    filters: {
        search?: string;
        status?: string;
        branch_id?: string | null;
    };
}

export default function ProductCategoriesIndex({ items, branches, filters }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<ProductCategory | null>(null);
    const [deleting, setDeleting] = useState<ProductCategory | null>(null);
    const [importOpen, setImportOpen] = useState(false);

    function openCreate() {
        setEditing(null);
        setFormOpen(true);
    }

    function openEdit(item: ProductCategory) {
        setEditing(item);
        setFormOpen(true);
    }

    function handleToggleStatus(item: ProductCategory) {
        router.patch(toggleStatus.url(item), {}, { preserveScroll: true });
    }

    function handleDelete() {
        if (!deleting) return;
        router.delete(destroy.url(deleting), {
            onFinish: () => setDeleting(null),
        });
    }

    const columns = useMemo<ColumnDef<ProductCategory>[]>(
        () => [
            {
                key: 'name',
                header: 'اسم الفئة',
                sortable: true,
                cell: (item) => (
                    <span className="flex items-center gap-2 font-medium">
                        {item.name}
                        {/* مدير الفرع يرى العامة والمشتركة ولا يملكها. */}
                        {!branches && !item.canEdit && (
                            <Badge variant="outline" className="text-muted-foreground font-normal">
                                {item.branches?.length ? 'مشتركة' : 'عامة'}
                            </Badge>
                        )}
                    </span>
                ),
            },
            ...(branches ? [{ key: 'branches', header: 'النطاق', cell: (item: ProductCategory) => scopeLabel(item) }] : []),
            {
                key: 'isActive',
                header: 'الحالة',
                cell: (item) => {
                    const badge = item.isActive ? (
                        <Badge variant="outline" className="gap-1.5 border-green-200 bg-green-50 text-green-700">
                            <span className="inline-block size-1.5 rounded-full bg-green-500" />
                            نشطة
                        </Badge>
                    ) : (
                        <Badge variant="outline" className="border-border bg-muted/60 text-muted-foreground gap-1.5">
                            <span className="bg-muted-foreground/50 inline-block size-1.5 rounded-full" />
                            غير نشطة
                        </Badge>
                    );

                    return item.canEdit ? (
                        <button onClick={() => handleToggleStatus(item)} className="cursor-pointer">
                            {badge}
                        </button>
                    ) : (
                        badge
                    );
                },
            },
            {
                key: 'actions',
                header: '',
                headerClassName: 'w-24',
                cell: (item) =>
                    item.canEdit && (
                        <div className="flex items-center gap-2">
                            <Button variant="outline" size="sm" onClick={() => openEdit(item)}>
                                <Pencil className="h-3.5 w-3.5" />
                            </Button>
                            <Button variant="outline" size="sm" className="text-destructive hover:text-destructive" onClick={() => setDeleting(item)}>
                                <Trash2 className="h-3.5 w-3.5" />
                            </Button>
                        </div>
                    ),
            },
        ],
        [branches],
    );

    const [search, setSearch] = useState(filters.search ?? '');
    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        status: filters.status ?? '',
        branch_id: filters.branch_id ?? '',
    });
    const query = (values: Record<string, string>) => Object.fromEntries(Object.entries(values).filter(([, v]) => v));
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            router.get(index.url(), query({ ...filterValues, search: value }), { preserveState: true, replace: true });
        }, 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        router.get(index.url(), query({ ...next, search }), { preserveState: true, replace: true });
    };

    const handleClearAll = () => {
        setSearch('');
        setFilterValues({ status: '', branch_id: '' });
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get(index.url(), {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-bold">فئات المنتجات</h1>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" size="sm" onClick={() => setImportOpen(true)}>
                            <Upload className="size-4" /> استيراد الكل
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <a href={categoryRoutes.export.url({ query: query({ status: filterValues.status, search }) })}>
                                <Download className="size-4" /> تصدير الكل
                            </a>
                        </Button>
                        <Button size="sm" onClick={openCreate}>
                            <Plus className="size-4" /> إضافة فئة
                        </Button>
                    </div>
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
                                    { value: '1', label: 'نشطة' },
                                    { value: '0', label: 'غير نشطة' },
                                ],
                            },
                            ...(branches
                                ? [
                                      {
                                          key: 'branch_id',
                                          placeholder: 'النطاق',
                                          options: [
                                              { value: 'global', label: 'عامة' },
                                              ...branches.map((b) => ({ value: b.id.toString(), label: b.name })),
                                          ],
                                      },
                                  ]
                                : []),
                        ]}
                        filterValues={filterValues}
                        onFilterChange={handleFilterChange}
                        onClearAll={handleClearAll}
                    />
                </div>

                <DataTable rowOffset={Number(items.meta.from ?? 1) - 1} columns={columns} data={items.data} keyExtractor={(item) => item.id} />

                <TablePagination
                    currentPage={items.meta.current_page as number}
                    totalPages={items.meta.last_page as number}
                    totalItems={items.meta.total as number}
                    from={items.meta.from as number}
                    to={items.meta.to as number}
                    onPageChange={(page) => {
                        router.reload({ data: { page } });
                    }}
                />
            </div>

            <Dialog open={!!deleting} onOpenChange={(open) => !open && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>هل أنت متأكد من حذف فئة "{deleting?.name}"؟ لا يمكن التراجع عن هذا الإجراء.</DialogDescription>
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
                title="استيراد فئات المنتجات"
                description="ملف Excel بعمودَي الاسم والحالة. الاستيراد يضيف ويحدّث فقط — لا يحذف فئة قائمة، فقد تكون معلّقة بمنتجات."
                previewUrl={categoryRoutes.importPreview.url()}
                commitUrl={categoryRoutes.import.url()}
                templateUrl={categoryRoutes.importTemplate.url()}
                onImported={() => router.reload()}
            />

            <ProductCategoryFormModal
                key={editing?.id ?? 'create'}
                open={formOpen}
                onOpenChange={setFormOpen}
                productCategory={editing ?? undefined}
                branches={branches}
            />
        </AppLayout>
    );
}
