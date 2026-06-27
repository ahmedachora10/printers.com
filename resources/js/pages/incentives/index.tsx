import { destroy, index, recalculate } from '@/actions/App/Http/Controllers/IncentiveController';
import PayBonusModal from '@/components/incentives/pay-bonus-modal';
import PlanFormModal from '@/components/incentives/plan-form-modal';
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
import { formatCurrency } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type EmployeeOption, type EnumOption, type IncentivePlan, type PaginatedIncentivePlan } from '@/types/incentive';
import { router } from '@inertiajs/react';
import { Pencil, Plus, RefreshCw, Trash2, Wallet } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'الحوافز والمكافآت', href: '/incentives' }];

const STATUS_VARIANT: Record<string, 'secondary' | 'default' | 'outline' | 'destructive'> = {
    active: 'secondary',
    achieved: 'default',
    missed: 'destructive',
    paid: 'outline',
};

interface Props {
    plans: PaginatedIncentivePlan;
    employees: EmployeeOption[];
    bonusTypes: EnumOption[];
    statuses: EnumOption[];
    branches?: { id: number; name: string }[] | null;
    filters: {
        search?: string;
        status?: string;
        period_month?: string;
        period_year?: string;
        branch_id?: string;
    };
}

export default function IncentivesIndex({ plans, employees, bonusTypes, statuses, branches, filters }: Props) {
    const isSuperAdmin = Array.isArray(branches);
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<IncentivePlan | null>(null);
    const [deleting, setDeleting] = useState<IncentivePlan | null>(null);
    const [paying, setPaying] = useState<IncentivePlan | null>(null);

    function openCreate() {
        setEditing(null);
        setFormOpen(true);
    }

    function openEdit(plan: IncentivePlan) {
        setEditing(plan);
        setFormOpen(true);
    }

    function handleDelete() {
        if (!deleting) return;
        router.delete(destroy.url(deleting), {
            preserveScroll: true,
            onFinish: () => setDeleting(null),
        });
    }

    function handleRecalculate() {
        router.post(recalculate.url(), {}, { preserveScroll: true });
    }

    const columns = useMemo<ColumnDef<IncentivePlan>[]>(
        () => [
            { key: 'employee', header: 'الموظف', cell: (p) => <span className="font-medium">{p.userName ?? '—'}</span> },
            ...(isSuperAdmin
                ? [{ key: 'branch', header: 'الفرع', cell: (p: IncentivePlan) => p.branchName ?? '—' }]
                : []),
            {
                key: 'period',
                header: 'الفترة',
                cell: (p) => <span className="tabular-nums" dir="ltr">{p.periodLabel}</span>,
            },
            { key: 'target', header: 'الهدف', cell: (p) => <span className="tabular-nums">{formatCurrency(p.targetAmount)}</span> },
            {
                key: 'achieved',
                header: 'المحقق',
                cell: (p) => (
                    <div className="min-w-28">
                        <div className="flex items-center justify-between gap-2 text-sm">
                            <span className="tabular-nums">{formatCurrency(p.achievedAmount)}</span>
                            <span className="text-muted-foreground text-xs tabular-nums">{p.progressPct}%</span>
                        </div>
                        <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                className={`h-full rounded-full ${p.isTargetMet ? 'bg-emerald-500' : 'bg-primary'}`}
                                style={{ width: `${Math.min(p.progressPct, 100)}%` }}
                            />
                        </div>
                    </div>
                ),
            },
            {
                key: 'bonus',
                header: 'المكافأة',
                cell: (p) => (
                    <div className="text-sm">
                        <span className="font-semibold tabular-nums">{formatCurrency(p.bonusAmount)}</span>
                        <span className="text-muted-foreground mr-1 text-xs">({p.bonusTypeLabel})</span>
                    </div>
                ),
            },
            {
                key: 'status',
                header: 'الحالة',
                cell: (p) => <Badge variant={STATUS_VARIANT[p.status] ?? 'secondary'}>{p.statusLabel}</Badge>,
            },
            {
                key: 'actions',
                header: '',
                headerClassName: 'w-32',
                cell: (p) => (
                    <div className="flex items-center justify-end gap-2">
                        {p.status === 'achieved' && (
                            <Button size="sm" onClick={() => setPaying(p)}>
                                <Wallet className="h-3.5 w-3.5" /> صرف
                            </Button>
                        )}
                        {p.status !== 'paid' && (
                            <>
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
                            </>
                        )}
                    </div>
                ),
            },
        ],
        [isSuperAdmin],
    );

    const [search, setSearch] = useState(filters.search ?? '');
    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        status: filters.status ?? '',
        branch_id: filters.branch_id ?? '',
    });
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    const buildQuery = (overrides: Record<string, string | undefined>) => {
        const params: Record<string, string> = {};
        const merged = { search, status: filterValues.status, branch_id: filterValues.branch_id, ...overrides };
        Object.entries(merged).forEach(([key, value]) => {
            if (value) params[key] = value;
        });
        return params;
    };

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            router.get(index.url(), buildQuery({ search: value }), { preserveState: true, replace: true });
        }, 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        router.get(index.url(), buildQuery({ [key]: val }), { preserveState: true, replace: true });
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
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">الحوافز والمكافآت</h1>
                    <Button variant="outline" size="sm" onClick={handleRecalculate}>
                        <RefreshCw className="size-4" /> تحديث المبيعات
                    </Button>
                </div>

                <div className="mb-6">
                    <FilterBar
                        searchable
                        searchPlaceholder="بحث باسم الموظف..."
                        searchValue={search}
                        onSearchChange={handleSearchChange}
                        filters={[
                            ...(isSuperAdmin
                                ? [
                                      {
                                          key: 'branch_id',
                                          placeholder: 'الفرع',
                                          options: branches!.map((b) => ({ value: b.id.toString(), label: b.name })),
                                      },
                                  ]
                                : []),
                            {
                                key: 'status',
                                placeholder: 'الحالة',
                                options: statuses.map((s) => ({ value: s.value, label: s.label })),
                            },
                        ]}
                        filterValues={filterValues}
                        onFilterChange={handleFilterChange}
                        onClearAll={handleClearAll}
                        actions={
                            <Button size="sm" onClick={openCreate}>
                                <Plus className="size-4" /> خطة حوافز
                            </Button>
                        }
                    />
                </div>

                <DataTable columns={columns} data={plans.data} keyExtractor={(p) => p.id} />

                <TablePagination
                    currentPage={plans.meta.current_page as number}
                    totalPages={plans.meta.last_page as number}
                    totalItems={plans.meta.total as number}
                    onPageChange={(page) =>
                        router.reload({ data: { page } })
                    }
                />
            </div>

            <Dialog open={!!deleting} onOpenChange={(open) => !open && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>
                            هل أنت متأكد من حذف خطة حوافز «{deleting?.userName}» للفترة {deleting?.periodLabel}؟
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

            <PlanFormModal
                key={editing?.id ?? 'create'}
                open={formOpen}
                onOpenChange={setFormOpen}
                plan={editing ?? undefined}
                employees={employees}
                bonusTypes={bonusTypes}
            />

            <PayBonusModal
                key={paying?.id ?? 'none'}
                open={!!paying}
                onOpenChange={(open) => !open && setPaying(null)}
                plan={paying}
            />
        </AppLayout>
    );
}
