import branchServiceRoutes, { destroy } from '@/actions/App/Http/Controllers/BranchServiceController';
import BranchServiceEmployeesModal, {
    type BranchEmployee,
    type EmployeeCommission,
} from '@/components/branch-services/branch-service-employees-modal';
import BranchServiceFormModal from '@/components/branch-services/branch-service-form-modal';
import BranchServiceMaterialsModal from '@/components/branch-services/branch-service-materials-modal';
import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { FilterBar } from '@/components/filter-bar';
import ImportDialog from '@/components/import/import-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { isMeasured, unitSuffix } from '@/lib/service-pricing';
import { formatCurrency } from '@/lib/utils';
import branchServicesRoute from '@/routes/branch-services';
import { type BreadcrumbItem } from '@/types';
import { type BranchProductOption, type BranchService } from '@/types/branch-service';
import { router } from '@inertiajs/react';
import { Download, Package, Pencil, Plus, Trash2, Upload, Users } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'خدمات الفرع', href: '/branch-services' }];

interface ServiceTemplateOption {
    id: number;
    name: string;
    /** خدمة أنشأها هذا الفرع لنفسه، لا خدمة عامة (تاسك 45) */
    isOwn?: boolean;
}

interface BranchOption {
    id: number;
    name: string;
}

interface PaginatedBranchService {
    data: BranchService[];
    links: unknown;
    meta: Record<string, unknown>;
}

interface Props {
    branchServices: PaginatedBranchService;
    serviceTemplates: ServiceTemplateOption[];
    /** منتجات الفرع المتاحة لربطها كخامة مخزون بخدمة (تاسك 50) */
    products: BranchProductOption[];
    userBranch: BranchOption | null;
    employees: BranchEmployee[];
    employeeCommissions: Record<number, EmployeeCommission[]>;
    filters: { search?: string; status?: string };
}

export default function BranchServicesIndex({
    branchServices,
    serviceTemplates,
    products,
    userBranch,
    employees,
    employeeCommissions,
    filters,
}: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [importOpen, setImportOpen] = useState(false);
    const [editingServiceId, setEditingServiceId] = useState<number | null>(null);
    const editingService = editingServiceId ? (branchServices.data.find((s) => s.id === editingServiceId) ?? null) : null;

    const [employeesServiceId, setEmployeesServiceId] = useState<number | null>(null);
    const employeesService = employeesServiceId ? (branchServices.data.find((s) => s.id === employeesServiceId) ?? null) : null;

    const [materialsServiceId, setMaterialsServiceId] = useState<number | null>(null);
    const materialsService = materialsServiceId ? (branchServices.data.find((s) => s.id === materialsServiceId) ?? null) : null;

    const [deletingServiceId, setDeletingServiceId] = useState<number | null>(null);
    const deletingService = deletingServiceId ? (branchServices.data.find((s) => s.id === deletingServiceId) ?? null) : null;

    function openCreate() {
        setEditingServiceId(null);
        setFormOpen(true);
    }

    function openEdit(service: BranchService) {
        setEditingServiceId(service.id);
        setFormOpen(true);
    }

    function handleDelete() {
        if (!deletingServiceId) return;
        router.delete(destroy.url({ id: deletingServiceId }), {
            onFinish: () => setDeletingServiceId(null),
        });
    }

    const columns = useMemo<ColumnDef<BranchService>[]>(
        () => [
            {
                key: 'serviceTemplateName',
                header: 'الخدمة',
                sortable: true,
                cell: (s) => <span className="font-medium">{s.serviceTemplateName ?? '—'}</span>,
            },
            {
                key: 'branchName',
                header: 'الفرع',
                cell: (s) => <span className="text-muted-foreground">{s.branchName}</span>,
            },
            {
                key: 'baseCommissionPct',
                header: 'نسبة العمولة',
                cell: (s) => <span className="text-primary font-medium">{s.baseCommissionPct}%</span>,
            },
            {
                key: 'maxDiscountPct',
                header: 'أقصى خصم',
                cell: (s) => <span>{s.maxDiscountPct}%</span>,
            },
            {
                key: 'maxSellingPrice',
                header: 'أعلى سعر',
                cell: (s) =>
                    s.maxSellingPrice !== null && s.maxSellingPrice > 0 ? (
                        <span className="tabular-nums">
                            {formatCurrency(s.maxSellingPrice)}
                            {isMeasured(s.pricingType) && (
                                <span className="text-muted-foreground text-xs"> /{unitSuffix(s.pricingType)}</span>
                            )}
                        </span>
                    ) : (
                        <span className="text-muted-foreground text-sm">مفتوح</span>
                    ),
            },
            {
                key: 'minSellingPrice',
                header: 'أقل سعر',
                cell: (s) =>
                    s.minSellingPrice !== null && s.minSellingPrice > 0 ? (
                        <span className="tabular-nums">
                            {formatCurrency(s.minSellingPrice)}
                            {isMeasured(s.pricingType) && (
                                <span className="text-muted-foreground text-xs"> /{unitSuffix(s.pricingType)}</span>
                            )}
                        </span>
                    ) : (
                        <span className="text-muted-foreground text-sm">مفتوح</span>
                    ),
            },
            {
                key: 'isTahazir',
                header: 'تحضير',
                cell: (s) =>
                    s.isTahazir ? (
                        <Badge
                            variant="outline"
                            className="border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-800 dark:bg-violet-950 dark:text-violet-300"
                        >
                            نعم
                        </Badge>
                    ) : (
                        <span className="text-muted-foreground text-sm">لا</span>
                    ),
            },
            {
                key: 'isActive',
                header: 'الحالة',
                cell: (s) =>
                    s.isActive ? (
                        <Badge variant="outline" className="gap-1.5 border-green-200 bg-green-50 text-green-700">
                            <span className="inline-block size-1.5 rounded-full bg-green-500" />
                            نشطة
                        </Badge>
                    ) : (
                        <Badge variant="outline" className="border-border bg-muted/60 text-muted-foreground gap-1.5">
                            <span className="bg-muted-foreground/50 inline-block size-1.5 rounded-full" />
                            غير نشطة
                        </Badge>
                    ),
            },
            {
                key: 'actions',
                header: '',
                headerClassName: 'w-36',
                cell: (s) => (
                    <div className="flex items-center gap-1.5">
                        <Button
                            variant="outline"
                            size="sm"
                            title={s.materials.length > 0 ? `خامات المخزون (${s.materials.length})` : 'خامات المخزون'}
                            onClick={() => setMaterialsServiceId(s.id)}
                        >
                            <Package className="h-3.5 w-3.5" />
                            {s.materials.length > 0 && <span className="text-[11px] tabular-nums">{s.materials.length}</span>}
                        </Button>
                        <Button variant="outline" size="sm" title="عمولات الموظفين" onClick={() => setEmployeesServiceId(s.id)}>
                            <Users className="h-3.5 w-3.5" />
                        </Button>
                        <Button variant="outline" size="sm" title="تعديل" onClick={() => openEdit(s)}>
                            <Pencil className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            title="حذف"
                            className="text-destructive hover:text-destructive"
                            onClick={() => setDeletingServiceId(s.id)}
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
        status: filters.status ?? '',
    });
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            router.get(
                branchServicesRoute.index().url,
                { ...(value && { search: value }), ...(filterValues.status && { status: filterValues.status }) },
                { preserveState: true, replace: true },
            );
        }, 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        router.get(
            branchServicesRoute.index().url,
            { ...(search && { search }), ...(next.status && { status: next.status }) },
            { preserveState: true, replace: true },
        );
    };

    const handleClearAll = () => {
        setSearch('');
        setFilterValues({ status: '' });
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get(branchServicesRoute.index().url, {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">خدمات الفرع</h1>
                        {userBranch && <p className="text-muted-foreground mt-0.5 text-sm">{userBranch.name}</p>}
                    </div>
                </div>

                <div className="mb-6">
                    <FilterBar
                        searchable
                        searchPlaceholder="بحث في الخدمات..."
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
                        ]}
                        filterValues={filterValues}
                        onFilterChange={handleFilterChange}
                        onClearAll={handleClearAll}
                        actions={
                            <>
                                <Button size="sm" variant="outline" asChild>
                                    <a href={branchServiceRoutes.export.url()}>
                                        <Download className="size-4" /> تصدير
                                    </a>
                                </Button>
                                <Button size="sm" variant="outline" onClick={() => setImportOpen(true)}>
                                    <Upload className="size-4" /> استيراد
                                </Button>
                                <Button size="sm" onClick={openCreate}>
                                    <Plus className="size-4" /> إضافة خدمة
                                </Button>
                            </>
                        }
                    />
                </div>

                <DataTable columns={columns} data={branchServices.data} keyExtractor={(s) => s.id} />

                <TablePagination
                    currentPage={branchServices.meta.current_page as number}
                    totalPages={branchServices.meta.last_page as number}
                    totalItems={branchServices.meta.total as number}
                    from={branchServices.meta.from as number}
                    to={branchServices.meta.to as number}
                    onPageChange={(page) => {
                        router.reload({ data: { page } });
                    }}
                />
            </div>

            <ImportDialog
                open={importOpen}
                onOpenChange={setImportOpen}
                title="استيراد خدمات الفرع"
                description="ملف Excel بورقتين: «خدمات الفرع» و«عمولات الموظفين». المطابقة باسم الخدمة — الموجودة تُحدّث، والاسم الجديد يُنشئ خدمةً مملوكة لفرعك، ولا يُحذف شيء. وعمودٌ غائب عن الملف يُترك كما هو."
                previewUrl={branchServiceRoutes.importPreview.url()}
                commitUrl={branchServiceRoutes.import.url()}
                templateUrl={branchServiceRoutes.importTemplate.url()}
                scope={{
                    options: null,
                    value: '',
                    onChange: () => {},
                    pinnedLabel: userBranch?.name ?? 'فرعك',
                    hint: 'كل صفوف الملف ستُنسب إلى فرعك — لا عمود «فرع» في هذه الورقة.',
                }}
                onImported={() => router.reload()}
            />

            {/* Delete confirmation */}
            <Dialog open={!!deletingServiceId} onOpenChange={(open) => !open && setDeletingServiceId(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>هل أنت متأكد من إزالة خدمة "{deletingService?.serviceTemplateName ?? ''}" من الفرع؟</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeletingServiceId(null)}>
                            إلغاء
                        </Button>
                        <Button variant="destructive" onClick={handleDelete}>
                            حذف
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Per-employee commission rates for a service */}
            <BranchServiceEmployeesModal
                key={employeesServiceId ?? 'employees'}
                open={!!employeesServiceId}
                onOpenChange={(open) => !open && setEmployeesServiceId(null)}
                branchServiceId={employeesServiceId}
                serviceName={employeesService?.serviceTemplateName ?? ''}
                employees={employees}
                current={employeesServiceId ? (employeeCommissions[employeesServiceId] ?? []) : []}
            />

            {/* خامات المخزون التي تستهلكها الخدمة (تاسك 50) */}
            <BranchServiceMaterialsModal
                key={materialsServiceId ?? 'materials'}
                open={!!materialsServiceId}
                onOpenChange={(open) => !open && setMaterialsServiceId(null)}
                branchServiceId={materialsServiceId}
                serviceName={materialsService?.serviceTemplateName ?? ''}
                isSqmService={isMeasured(materialsService?.pricingType ?? 'unit')}
                products={products}
                current={materialsService?.materials ?? []}
            />

            {/* Create / Edit modal */}
            {userBranch && (
                <BranchServiceFormModal
                    key={editingServiceId ?? 'create'}
                    open={formOpen}
                    onOpenChange={(open) => {
                        setFormOpen(open);
                        if (!open) setEditingServiceId(null);
                    }}
                    userBranch={userBranch}
                    serviceTemplates={serviceTemplates}
                    branchService={editingService ?? undefined}
                />
            )}
        </AppLayout>
    );
}
