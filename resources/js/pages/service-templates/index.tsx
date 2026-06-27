import { destroy, index } from '@/actions/App/Http/Controllers/ServiceTemplateController';
import { type BranchEmployee, type EmployeeCommission } from '@/components/branch-services/branch-service-employees-modal';
import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { FilterBar } from '@/components/filter-bar';
import BranchServiceManageModal from '@/components/service-templates/branch-service-manage-modal';
import ServiceTemplateFormModal from '@/components/service-templates/service-template-form-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type PaginatedServiceTemplate, type ServiceTemplate } from '@/types/service-template';
import { router } from '@inertiajs/react';
import { Network, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'قوالب الخدمات', href: '/service-templates' }];

interface BranchOption {
    id: number;
    name: string;
}

interface Props {
    templates: PaginatedServiceTemplate;
    branches: BranchOption[];
    branchEmployees: Record<number, BranchEmployee[]>;
    employeeCommissions: Record<number, EmployeeCommission[]>;
    filters: {
        search?: string;
        status?: string;
    };
}

export default function ServiceTemplatesIndex({ templates, branches, branchEmployees, employeeCommissions, filters }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [editingTemplateId, setEditingTemplateId] = useState<number | null>(null);
    const editingTemplate = editingTemplateId ? (templates.data.find((t) => t.id === editingTemplateId) ?? null) : null;

    const [managingTemplateId, setManagingTemplateId] = useState<number | null>(null);
    const managingTemplate = managingTemplateId ? (templates.data.find((t) => t.id === managingTemplateId) ?? null) : null;

    const [deletingTemplateId, setDeletingTemplateId] = useState<number | null>(null);
    const deletingTemplate = deletingTemplateId ? (templates.data.find((t) => t.id === deletingTemplateId) ?? null) : null;

    function openCreate() {
        setEditingTemplateId(null);
        setFormOpen(true);
    }

    function openEdit(template: ServiceTemplate) {
        setEditingTemplateId(template.id);
        setFormOpen(true);
    }

    function handleDelete() {
        if (!deletingTemplateId) return;
        router.delete(destroy.url({ id: deletingTemplateId }), {
            onFinish: () => setDeletingTemplateId(null),
        });
    }

    const columns = useMemo<ColumnDef<ServiceTemplate>[]>(
        () => [
            {
                key: 'name',
                header: 'اسم الخدمة',
                sortable: true,
                cell: (template) => (
                    <div>
                        <p className="font-medium">{template.name}</p>
                        {template.description && <p className="text-muted-foreground line-clamp-1 text-xs">{template.description}</p>}
                    </div>
                ),
            },
            {
                key: 'branches',
                header: 'الفروع',
                cell: (template) => <span className="text-muted-foreground text-sm">{template.branches?.length ?? 0} فرع</span>,
            },
            {
                key: 'isActive',
                header: 'الحالة',
                cell: (template) =>
                    template.isActive ? (
                        <Badge variant="outline" className="gap-1.5 border-green-200 bg-green-50 text-green-700">
                            <span className="inline-block size-1.5 rounded-full bg-green-500" />
                            نشط
                        </Badge>
                    ) : (
                        <Badge variant="outline" className="border-border bg-muted/60 text-muted-foreground gap-1.5">
                            <span className="bg-muted-foreground/50 inline-block size-1.5 rounded-full" />
                            غير نشط
                        </Badge>
                    ),
            },
            {
                key: 'actions',
                header: '',
                headerClassName: 'w-36',
                cell: (template) => (
                    <div className="flex items-center gap-1.5">
                        <Button variant="outline" size="sm" title="إدارة الفروع" onClick={() => setManagingTemplateId(template.id)}>
                            <Network className="h-3.5 w-3.5" />
                        </Button>
                        <Button variant="outline" size="sm" title="تعديل" onClick={() => openEdit(template)}>
                            <Pencil className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            title="حذف"
                            className="text-destructive hover:text-destructive"
                            onClick={() => setDeletingTemplateId(template.id)}
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
                index.url(),
                {
                    ...(value && { search: value }),
                    ...(filterValues.status && { status: filterValues.status }),
                },
                { preserveState: true, replace: true },
            );
        }, 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        router.get(index.url(), { ...(search && { search }), ...(next.status && { status: next.status }) }, { preserveState: true, replace: true });
    };

    const handleClearAll = () => {
        setSearch('');
        setFilterValues({ status: '' });
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get(index.url(), {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">إدارة قوالب الخدمات</h1>
                </div>

                <div className="mb-6">
                    <FilterBar
                        searchable
                        searchPlaceholder="بحث في قوالب الخدمات..."
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
                                <Plus className="size-4" /> إضافة قالب
                            </Button>
                        }
                    />
                </div>

                <DataTable columns={columns} data={templates.data} keyExtractor={(t) => t.id} />

                <TablePagination
                    currentPage={templates.meta.current_page as number}
                    totalPages={templates.meta.last_page as number}
                    totalItems={templates.meta.total as number}
                    onPageChange={(page) => {
                        router.reload({ data: { page } });
                    }}
                />
            </div>

            {/* Delete confirmation dialog */}
            <Dialog open={!!deletingTemplateId} onOpenChange={(open) => !open && setDeletingTemplateId(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>
                            هل أنت متأكد من حذف قالب "{deletingTemplate?.name}"؟ لا يمكن حذف القالب إذا كان مرتبطاً بفروع.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeletingTemplateId(null)}>
                            إلغاء
                        </Button>
                        <Button variant="destructive" onClick={handleDelete}>
                            حذف
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Create / Edit modal */}
            <ServiceTemplateFormModal
                key={editingTemplateId ?? 'create'}
                open={formOpen}
                onOpenChange={(open) => {
                    setFormOpen(open);
                    if (!open) setEditingTemplateId(null);
                }}
                template={editingTemplate ?? undefined}
            />

            {/* Manage branch services modal */}
            <BranchServiceManageModal
                key={managingTemplateId ?? 'manage'}
                open={!!managingTemplateId}
                onOpenChange={(open) => {
                    if (!open) setManagingTemplateId(null);
                }}
                template={managingTemplate}
                branches={branches}
                branchEmployees={branchEmployees}
                employeeCommissions={employeeCommissions}
            />
        </AppLayout>
    );
}
