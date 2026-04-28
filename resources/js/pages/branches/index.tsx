import { destroy, index, toggleStatus } from '@/actions/App/Http/Controllers/BranchController';
import BranchFormModal from '@/components/branches/branch-form-modal';
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
import { type Branch, type PaginatedBranch } from '@/types/branch';
import { type City } from '@/types/city';
import { router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'الفروع', href: '/branches' },
];

interface Props {
    branches: PaginatedBranch;
    cities: City[];
    filters: {
        search?: string;
        status?: string;
    };
}

export default function BranchesIndex({ branches, cities, filters }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [editingBranch, setEditingBranch] = useState<Branch | null>(null);
    const [deletingBranch, setDeletingBranch] = useState<Branch | null>(null);

    function openCreate() {
        setEditingBranch(null);
        setFormOpen(true);
    }

    function openEdit(branch: Branch) {
        setEditingBranch(branch);
        setFormOpen(true);
    }

    function handleToggleStatus(branch: Branch) {
        router.patch(toggleStatus.url(branch), {}, { preserveScroll: true });
    }

    function handleDelete() {
        if (!deletingBranch) return;
        router.delete(destroy.url(deletingBranch), {
            onFinish: () => setDeletingBranch(null),
        });
    }

    const columns = useMemo<ColumnDef<Branch>[]>(
        () => [
            {
                key: 'name',
                header: 'اسم الفرع',
                sortable: true,
                cell: (branch) => (
                    <div className="flex items-center gap-3">
                        {branch.logoUrl ? (
                            <img src={branch.logoUrl} alt={branch.name} className="size-8 rounded object-contain" />
                        ) : (
                            <div className="size-8 rounded bg-muted" />
                        )}
                        <span className="font-medium">{branch.name}</span>
                    </div>
                ),
            },
            {
                key: 'city',
                header: 'المدينة',
                cell: (branch) => <span className="text-muted-foreground">{branch.city?.name ?? '—'}</span>,
            },
            {
                key: 'phone',
                header: 'الهاتف',
                cell: (branch) => <span className="text-muted-foreground">{branch.phone ?? '—'}</span>,
            },
            {
                key: 'vatRateOverride',
                header: 'نسبة الضريبة',
                cell: (branch) => <span>{branch.vatRateOverride}%</span>,
            },
            {
                key: 'isActive',
                header: 'الحالة',
                cell: (branch) => (
                    <button onClick={() => handleToggleStatus(branch)} className="cursor-pointer">
                        {branch.isActive ? (
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
                cell: (branch) => (
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" onClick={() => openEdit(branch)}>
                            <Pencil className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={() => setDeletingBranch(branch)}
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
                { ...(value && { search: value }), ...(filterValues.status && { status: filterValues.status }) },
                { preserveState: true, replace: true },
            );
        }, 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        router.get(
            index.url(),
            { ...(search && { search }), ...(next.status && { status: next.status }) },
            { preserveState: true, replace: true },
        );
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
                    <h1 className="text-2xl font-bold">إدارة الفروع</h1>
                </div>

                <div className="mb-6">
                    <FilterBar
                        searchable
                        searchPlaceholder="بحث في الفروع..."
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
                                <Plus className="size-4" /> إضافة فرع
                            </Button>
                        }
                    />
                </div>

                <DataTable
                    columns={columns}
                    data={branches.data}
                    keyExtractor={(branch) => branch.id}
                    defaultPageSize={15}
                />
            </div>

            {/* Delete confirmation dialog */}
            <Dialog open={!!deletingBranch} onOpenChange={(open) => !open && setDeletingBranch(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>
                            هل أنت متأكد من حذف فرع "{deletingBranch?.name}"؟ لا يمكن التراجع عن هذا الإجراء.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeletingBranch(null)}>
                            إلغاء
                        </Button>
                        <Button variant="destructive" onClick={handleDelete}>
                            حذف
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <BranchFormModal
                key={editingBranch?.id ?? 'create'}
                open={formOpen}
                onOpenChange={setFormOpen}
                branch={editingBranch ?? undefined}
                cities={cities}
            />
        </AppLayout>
    );
}
