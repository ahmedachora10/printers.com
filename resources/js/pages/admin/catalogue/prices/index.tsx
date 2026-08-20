import priceRoutes from '@/actions/App/Http/Controllers/CatalogPriceController';
import subcategoryRoutes from '@/actions/App/Http/Controllers/CatalogSubcategoryController';
import PriceFormModal from '@/components/catalogue/price-form-modal';
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
import ImportDialog from '@/components/import/import-dialog';
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
import { type CatalogPrice, type CatalogSubcategory, type Paginated } from '@/types/catalogue';
import { Link, router } from '@inertiajs/react';
import { Download, Pencil, Plus, Trash2, Upload } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

interface Props extends CatalogueScope {
    subcategory: CatalogSubcategory & { categoryId: number };
    prices: Paginated<CatalogPrice>;
    filters: { search?: string; status?: string; branch?: string };
}

function formatSar(value: number): string {
    return `${value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ر.س`;
}

export default function CatalogPricesIndex({ subcategory, prices, filters, branches, ownBranchId, ownBranchName }: Props) {
    const scope: CatalogueScope = { branches, ownBranchId, ownBranchName };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'دليل الخدمات', href: '/admin/catalogue' },
        { title: subcategory.nameAr, href: priceRoutes.index.url(subcategory.id) },
    ];

    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<CatalogPrice | null>(null);
    const [deleting, setDeleting] = useState<CatalogPrice | null>(null);
    const [importOpen, setImportOpen] = useState(false);
    // Opening suggestion only: the dialog asks where the sheet lands (تاسك 47).
    const [importBranch, setImportBranch] = useState(filters.branch ?? GENERAL);

    function openCreate() {
        setEditing(null);
        setFormOpen(true);
    }

    function openEdit(price: CatalogPrice) {
        setEditing(price);
        setFormOpen(true);
    }

    function handleToggle(price: CatalogPrice) {
        router.patch(priceRoutes.toggleStatus.url(price), {}, { preserveScroll: true });
    }

    function handleDelete() {
        if (!deleting) return;
        router.delete(priceRoutes.destroy.url(deleting), {
            preserveScroll: true,
            onFinish: () => setDeleting(null),
        });
    }

    const columns = useMemo<ColumnDef<CatalogPrice>[]>(
        () => [
            { key: 'name', header: 'الاسم', cell: (p) => <span className="font-medium">{p.name}</span> },
            {
                key: 'owner',
                header: 'النطاق',
                cell: (p) => <ScopeBadge branchId={p.branchId} branchName={p.branchName} />,
            },
            {
                key: 'range',
                header: 'نطاق السعر',
                cell: (p) => (
                    <span className="text-muted-foreground">
                        {formatSar(p.minPrice)} – {formatSar(p.maxPrice)}
                    </span>
                ),
            },
            { key: 'basePrice', header: 'السعر الأساسي', cell: (p) => <span>{formatSar(p.basePrice)}</span> },
            { key: 'sortOrder', header: 'الترتيب', cell: (p) => <span className="text-muted-foreground">{p.sortOrder}</span> },
            {
                key: 'isActive',
                header: 'الحالة',
                cell: (p) => (
                    <button onClick={() => canEditRow(scope, p.branchId) && handleToggle(p)} disabled={!canEditRow(scope, p.branchId)} className="cursor-pointer disabled:cursor-default">
                        {p.isActive ? (
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
                cell: (p) =>
                    canEditRow(scope, p.branchId) ? (
                        <div className="flex items-center justify-end gap-2">
                            <Button variant="outline" size="sm" onClick={() => openEdit(p)}>
                                <Pencil className="h-3.5 w-3.5" />
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                className="text-destructive hover:text-destructive"
                                onClick={() => setDeleting(p)}
                            >
                                <Trash2 className="h-3.5 w-3.5" />
                            </Button>
                        </div>
                    ) : (
                        <span className="block text-end text-xs text-muted-foreground">للقراءة فقط</span>
                    ),
            },
        ],
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [branches, ownBranchId],
    );

    const [search, setSearch] = useState(filters.search ?? '');
    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        status: filters.status ?? '',
        branch: filters.branch ?? '',
    });
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    const listUrl = priceRoutes.index.url(subcategory.id);

    // Export exactly the scope on screen, so the sheet round-trips back into it.
    const exportUrl = filterValues.branch
        ? `${priceRoutes.export.url(subcategory.id)}?branch=${filterValues.branch}`
        : priceRoutes.export.url(subcategory.id);

    const buildQuery = (values: Record<string, string>, term: string) => ({
        ...(term && { search: term }),
        ...(values.status && { status: values.status }),
        ...(values.branch && { branch: values.branch }),
    });

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            router.get(listUrl, buildQuery(filterValues, value), { preserveState: true, replace: true });
        }, 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        router.get(listUrl, buildQuery(next, search), { preserveState: true, replace: true });
    };

    const handleClearAll = () => {
        setSearch('');
        setFilterValues({ status: '', branch: '' });
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get(listUrl, {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">بنود الأسعار</h1>
                        <p className="text-sm text-muted-foreground">
                            الخدمة: {subcategory.nameAr}
                            {' — '}
                            {scopeHint(
                                scope,
                                'الأسعار العامة تسري على كل الفروع، وسعر الفرع يعلو عليها.',
                                'ما تضيفه هنا سعر فرعك وحده، والأسعار العامة للقراءة فقط.',
                            )}
                        </p>
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <Link href={subcategoryRoutes.index.url(subcategory.categoryId)}>العودة للخدمات</Link>
                    </Button>
                </div>

                <div className="mb-6">
                    <FilterBar
                        searchable
                        searchPlaceholder="بحث في الأسعار..."
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
                                    <Upload className="size-4" /> استيراد
                                </Button>
                                <Button variant="outline" size="sm" asChild>
                                    <a href={exportUrl}>
                                        <Download className="size-4" /> تصدير
                                    </a>
                                </Button>
                                <Button size="sm" onClick={openCreate}>
                                    <Plus className="size-4" /> إضافة بند
                                </Button>
                            </>
                        }
                    />
                </div>

                <DataTable columns={columns} data={prices.data} keyExtractor={(p) => p.id} />

                <TablePagination
                    currentPage={prices.meta.current_page as number}
                    totalPages={prices.meta.last_page as number}
                    totalItems={prices.meta.total as number}
                    onPageChange={(page) => router.reload({ data: { page } })}
                />
            </div>

            <Dialog open={!!deleting} onOpenChange={(open) => !open && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>هل أنت متأكد من حذف بند "{deleting?.name}"؟</DialogDescription>
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
                title={`استيراد أسعار: ${subcategory.nameAr}`}
                description="ملف Excel ببنود هذه الخدمة الفرعية وأسعارها. الاستيراد يضيف ويحدّث فقط — لا يحذف أي بند قائم."
                previewUrl={priceRoutes.importPreview.url(subcategory.id)}
                commitUrl={priceRoutes.import.url(subcategory.id)}
                templateUrl={priceRoutes.importTemplate.url(subcategory.id)}
                payload={{ branch: importBranch }}
                scope={catalogueImportScope(scope, importBranch, setImportBranch)}
                onImported={() => router.reload()}
            />

            <PriceFormModal
                key={editing?.id ?? 'create'}
                open={formOpen}
                onOpenChange={setFormOpen}
                subcategoryId={subcategory.id}
                price={editing ?? undefined}
                branches={branches}
                defaultBranchId={defaultBranchFor(scope, filterValues.branch)}
            />
        </AppLayout>
    );
}
