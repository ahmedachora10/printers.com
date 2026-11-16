import { destroy, index, toggleStatus } from '@/actions/App/Http/Controllers/BranchController';
import BranchFormModal from '@/components/branches/branch-form-modal';
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
import { formatDateNumeric } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type Branch, type BranchAdmin, type PaginatedBranch } from '@/types/branch';
import { type City } from '@/types/city';
import { router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'الفروع', href: '/branches' },
];

/** تاسك 228: الأيام المتبقية بلون الاقتراب، أو المهلة، أو «منتهي». */
function SubscriptionCell({ branch }: { branch: Branch }) {
    const days = branch.subscriptionDaysLeft;

    switch (branch.subscriptionState) {
        case null:
            return <span className="text-muted-foreground">—</span>;
        case 'expired':
            return <Badge variant="destructive">منتهي</Badge>;
        case 'grace':
            return <span className="text-sm font-medium text-red-600">مهلة حتى {formatDateNumeric(branch.subscriptionGraceUntil!)}</span>;
        default:
            return (
                <span
                    className={days! <= 7 ? 'font-medium text-red-600' : days! <= 30 ? 'font-medium text-amber-600' : 'text-muted-foreground'}
                    title={`ينتهي ${formatDateNumeric(branch.subscriptionEndsAt!)}`}
                >
                    باقٍ {days} يوم
                </span>
            );
    }
}

interface Props {
    branches: PaginatedBranch;
    cities: City[];
    branchAdmins: BranchAdmin[];
    filters: {
        search?: string;
        status?: string;
    };
}

export default function BranchesIndex({ branches, cities, branchAdmins, filters }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [editingBranch, setEditingBranch] = useState<Branch | null>(null);
    const [deletingBranch, setDeletingBranch] = useState<Branch | null>(null);
    const [freezingBranch, setFreezingBranch] = useState<Branch | null>(null);

    function openCreate() {
        setEditingBranch(null);
        setFormOpen(true);
    }

    function openEdit(branch: Branch) {
        setEditingBranch(branch);
        setFormOpen(true);
    }

    function handleToggleStatus(branch: Branch) {
        router.patch(toggleStatus.url(branch), {}, { preserveScroll: true, onFinish: () => setFreezingBranch(null) });
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
                key: 'owner',
                header: 'المالك',
                cell: (branch) => <span className="text-muted-foreground">{branch.owner?.name ?? '—'}</span>,
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
                key: 'subscription',
                header: 'الاشتراك',
                cell: (branch) => <SubscriptionCell branch={branch} />,
            },
            {
                key: 'isActive',
                header: 'الحالة',
                cell: (branch) => (
                    // التجميد يطرد كل مستخدمي الفرع — يُؤكَّد؛ فكّه مباشر.
                    <button onClick={() => (branch.isActive ? setFreezingBranch(branch) : handleToggleStatus(branch))} className="cursor-pointer">
                        {branch.isActive ? (
                            <Badge variant="outline" className="gap-1.5 border-green-200 bg-green-50 text-green-700">
                                <span className="inline-block size-1.5 rounded-full bg-green-500" />
                                نشط
                            </Badge>
                        ) : (
                            <Badge variant="outline" className="gap-1.5 border-border bg-muted/60 text-muted-foreground">
                                <span className="inline-block size-1.5 rounded-full bg-muted-foreground/50" />
                                مجمَّد
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
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-2xl font-bold">إدارة الفروع</h1>
                    <Button size="sm" onClick={openCreate}>
                        <Plus className="size-4" /> إضافة فرع
                    </Button>
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
                                    { value: '0', label: 'مجمَّد' },
                                ],
                            },
                        ]}
                        filterValues={filterValues}
                        onFilterChange={handleFilterChange}
                        onClearAll={handleClearAll}
                    />
                </div>

                <DataTable
                    rowOffset={Number(branches.meta.from ?? 1) - 1}
                    columns={columns}
                    data={branches.data}
                    keyExtractor={(branch) => branch.id}
                />

                <TablePagination
                    currentPage={branches.meta.current_page as number}
                    totalPages={branches.meta.last_page as number}
                    totalItems={branches.meta.total as number}
                    from={branches.meta.from as number}
                    to={branches.meta.to as number}
                    onPageChange={(page) => {
                        router.reload({ data: { page } });
                    }}
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

            {/* Freeze confirmation dialog */}
            <Dialog open={!!freezingBranch} onOpenChange={(open) => !open && setFreezingBranch(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تجميد الفرع</DialogTitle>
                        <DialogDescription>
                            تجميد فرع "{freezingBranch?.name}" يمنع كل مستخدميه من الدخول ويُخرج من هو داخل النظام الآن، حتى يُعاد تفعيله.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setFreezingBranch(null)}>
                            إلغاء
                        </Button>
                        <Button variant="destructive" onClick={() => freezingBranch && handleToggleStatus(freezingBranch)}>
                            تجميد
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
                branchAdmins={branchAdmins}
            />
        </AppLayout>
    );
}
