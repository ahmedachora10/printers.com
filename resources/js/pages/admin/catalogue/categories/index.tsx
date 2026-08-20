import categoryRoutes from '@/actions/App/Http/Controllers/CatalogCategoryController';
import subcategoryRoutes from '@/actions/App/Http/Controllers/CatalogSubcategoryController';
import CategoryFormModal from '@/components/catalogue/category-form-modal';
import ImportDialog from '@/components/import/import-dialog';
import {
    branchFilterOptions,
    canEditRow,
    catalogueImportScope,
    defaultBranchFor,
    GENERAL,
    scopeHint,
    ScopeBadge,
    type CatalogueScope,
} from '@/components/catalogue/scope';
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
import { Download, ImageIcon, Layers, Pencil, Plus, Trash2, Upload } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'دليل الخدمات', href: '/admin/catalogue' }];

interface Props extends CatalogueScope {
    categories: Paginated<CatalogCategory>;
    filters: { search?: string; status?: string; branch?: string };
}

export default function CatalogCategoriesIndex({ categories, filters, branches, ownBranchId, ownBranchName }: Props) {
    const scope: CatalogueScope = { branches, ownBranchId, ownBranchName };

    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<CatalogCategory | null>(null);
    const [deleting, setDeleting] = useState<CatalogCategory | null>(null);
    const [importOpen, setImportOpen] = useState(false);

    const [search, setSearch] = useState(filters.search ?? '');
    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        status: filters.status ?? '',
        branch: filters.branch ?? '',
    });
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    // The destination the sheet lands on: the branch filter on screen is only
    // the opening suggestion — the dialog asks, so an import can never land
    // somewhere the user did not read out loud.
    const [importBranch, setImportBranch] = useState(filters.branch ?? GENERAL);

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
                key: 'owner',
                header: 'النطاق',
                cell: (c) => <ScopeBadge branchId={c.branchId} branchName={c.branchName} />,
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
                    <button
                        onClick={() => canEditRow(scope, c.branchId) && handleToggle(c)}
                        disabled={!canEditRow(scope, c.branchId)}
                        className="cursor-pointer disabled:cursor-default"
                    >
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
                        {canEditRow(scope, c.branchId) ? (
                            <>
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
                            </>
                        ) : (
                            <span className="text-xs text-muted-foreground">عامة</span>
                        )}
                    </div>
                ),
            },
        ],
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [branches, ownBranchId],
    );

    const buildQuery = (values: Record<string, string>, term: string) => ({
        ...(term && { search: term }),
        ...(values.status && { status: values.status }),
        ...(values.branch && { branch: values.branch }),
    });

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            router.get(categoryRoutes.index.url(), buildQuery(filterValues, value), { preserveState: true, replace: true });
        }, 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        router.get(categoryRoutes.index.url(), buildQuery(next, search), { preserveState: true, replace: true });
    };

    const handleClearAll = () => {
        setSearch('');
        setFilterValues({ status: '', branch: '' });
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get(categoryRoutes.index.url(), {}, { preserveState: true, replace: true });
    };

    // Export exactly the scope on screen, so the sheet round-trips back into it.
    const exportUrl = filterValues.branch
        ? `${categoryRoutes.export.url()}?branch=${filterValues.branch}`
        : categoryRoutes.export.url();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">دليل الخدمات — الفئات</h1>
                        <p className="text-sm text-muted-foreground">
                            {scopeHint(
                                scope,
                                'الفئات العامة يراها كل فرع، وما تضيفه لفرع بعينه لا يظهر إلا فيه.',
                                'تضيف فئات فرعك وتحرّرها، والفئات العامة تراها ولا تعدّلها.',
                            )}
                        </p>
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
                                    { value: '1', label: 'نشط' },
                                    { value: '0', label: 'غير نشط' },
                                ],
                            },
                            ...branchFilterOptions(scope),
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
                                    <Plus className="size-4" /> إضافة فئة
                                </Button>
                            </>
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

            <ImportDialog
                open={importOpen}
                onOpenChange={setImportOpen}
                title="استيراد دليل الخدمات"
                description="ملف Excel واحد يحمل الفئات وخدماتها الفرعية وبنود أسعارها. الاستيراد يضيف ويحدّث فقط — لا يحذف أي بند قائم."
                previewUrl={categoryRoutes.importPreview.url()}
                commitUrl={categoryRoutes.import.url()}
                templateUrl={categoryRoutes.importTemplate.url()}
                payload={{ branch: importBranch }}
                scope={catalogueImportScope(scope, importBranch, setImportBranch)}
                onImported={() => router.reload()}
            />

            <CategoryFormModal
                key={editing?.id ?? 'create'}
                open={formOpen}
                onOpenChange={setFormOpen}
                category={editing ?? undefined}
                branches={branches}
                defaultBranchId={defaultBranchFor(scope, filterValues.branch)}
            />
        </AppLayout>
    );
}
