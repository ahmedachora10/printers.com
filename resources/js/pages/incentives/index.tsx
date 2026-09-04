import { destroy, index, recalculate } from '@/actions/App/Http/Controllers/IncentiveController';
import { destroy as destroyDeduction } from '@/actions/App/Http/Controllers/EmployeeDeductionController';
import DeductionFormModal from '@/components/incentives/deduction-form-modal';
import PayBonusModal from '@/components/incentives/pay-bonus-modal';
import PlanFormModal from '@/components/incentives/plan-form-modal';
import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { TableCell, TableRow } from '@/components/ui/table';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import DateRangeBar from '@/components/reports/date-range-bar';
import { FilterSelect } from '@/components/reports/filter-fields';
import { FilterModal } from '@/components/reports/filter-modal';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
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
import {
    type DeductionReasonOption,
    type EmployeeDeduction,
    type EmployeeOption,
    type EnumOption,
    type IncentivePlan,
    type PaginatedEmployeeDeduction,
    type PaginatedIncentivePlan,
} from '@/types/incentive';
import { router } from '@inertiajs/react';
import { Minus, Pencil, Plus, RefreshCw, Trash2, Wallet } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'الحوافز والمكافآت', href: '/incentives' }];

const STATUS_VARIANT: Record<string, 'secondary' | 'default' | 'outline' | 'destructive'> = {
    active: 'secondary',
    achieved: 'default',
    missed: 'destructive',
    paid: 'outline',
};

interface Props {
    plans: PaginatedIncentivePlan;
    /** تاسك 74: سجل الخصومات — بند مستقل تحت الحوافز. */
    deductions: PaginatedEmployeeDeduction;
    deductionsTotal: number;
    deductionReasons: DeductionReasonOption[];
    employees: EmployeeOption[];
    bonusTypes: EnumOption[];
    statuses: EnumOption[];
    branches?: { id: number; name: string }[] | null;
    filters: {
        employee?: string | null;
        status?: string | null;
        from?: string | null;
        to?: string | null;
        branch?: string | null;
    };
}

export default function IncentivesIndex({
    plans,
    deductions,
    deductionsTotal,
    deductionReasons,
    employees,
    bonusTypes,
    statuses,
    branches,
    filters,
}: Props) {
    const isSuperAdmin = Array.isArray(branches);
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<IncentivePlan | null>(null);
    const [deleting, setDeleting] = useState<IncentivePlan | null>(null);
    const [paying, setPaying] = useState<IncentivePlan | null>(null);
    const [deductionOpen, setDeductionOpen] = useState(false);
    const [deletingDeduction, setDeletingDeduction] = useState<EmployeeDeduction | null>(null);

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

    function handleDeleteDeduction() {
        if (!deletingDeduction) return;
        router.delete(destroyDeduction.url(deletingDeduction), {
            preserveScroll: true,
            onFinish: () => setDeletingDeduction(null),
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

    const deductionColumns = useMemo<ColumnDef<EmployeeDeduction>[]>(
        () => [
            {
                key: 'deductedAt',
                header: 'التاريخ',
                cell: (d) => <span className="tabular-nums whitespace-nowrap">{d.deductedAt}</span>,
            },
            {
                key: 'userName',
                header: 'الموظف',
                cell: (d) => <span className="font-medium">{d.userName ?? '—'}</span>,
            },
            ...(isSuperAdmin
                ? [
                      {
                          key: 'branchName',
                          header: 'الفرع',
                          cell: (d: EmployeeDeduction) => d.branchName ?? '—',
                      },
                  ]
                : []),
            {
                key: 'amount',
                header: 'القيمة',
                cell: (d) => (
                    <span className="font-semibold tabular-nums text-destructive">{formatCurrency(d.amount)}</span>
                ),
            },
            {
                key: 'reason',
                header: 'السبب',
                cell: (d) => <span className="text-sm">{d.reasonText}</span>,
            },
            {
                key: 'deductedBy',
                header: 'بواسطة',
                cell: (d) => d.deductedBy ?? '—',
            },
            {
                key: 'actions',
                header: '',
                headerClassName: 'w-16',
                cell: (d) => (
                    <div className="flex items-center justify-end">
                        <Button
                            variant="outline"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={() => setDeletingDeduction(d)}
                        >
                            <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                    </div>
                ),
            },
        ],
        [isSuperAdmin],
    );

    // نفس آليّة تصفية التقارير: الفروق كلها في الحقول، فلا نسخةَ ثانية من منطق
    // المسوّدة والتنقّل. والمدى هنا يبدأ فارغاً — القائمة تُفتح على كل الفترات.
    const defaults = useMemo<FilterValues>(() => ({ from: '', to: '', employee: 'all', branch: 'all', status: 'all' }), []);

    const applied: FilterValues = {
        from: filters.from ?? '',
        to: filters.to ?? '',
        employee: filters.employee ?? 'all',
        branch: filters.branch ?? 'all',
        status: filters.status ?? 'all',
    };
    const f = useReportFilters(index.url(), applied, defaults);

    const chips: FilterChip[] = [];
    if (f.isActive('branch')) {
        const name = branches?.find((b) => b.id.toString() === applied.branch)?.name ?? applied.branch;
        chips.push({ key: 'branch', label: `الفرع: ${name}`, onRemove: () => f.remove('branch') });
    }
    if (f.isActive('employee')) {
        const name = employees.find((e) => e.id.toString() === applied.employee)?.name ?? applied.employee;
        chips.push({ key: 'employee', label: `الموظف: ${name}`, onRemove: () => f.remove('employee') });
    }
    if (f.isActive('status')) {
        const label = statuses.find((s) => s.value === applied.status)?.label ?? applied.status;
        chips.push({ key: 'status', label: `الحالة: ${label}`, onRemove: () => f.remove('status') });
    }
    if (f.isActive('from')) {
        chips.push({ key: 'from', label: `من: ${applied.from}`, onRemove: () => f.remove('from') });
    }
    if (f.isActive('to')) {
        chips.push({ key: 'to', label: `إلى: ${applied.to}`, onRemove: () => f.remove('to') });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">الحوافز والمكافآت</h1>
                    <Button variant="outline" size="sm" onClick={handleRecalculate}>
                        <RefreshCw className="size-4" /> تحديث المبيعات
                    </Button>
                </div>

                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <DateRangeBar filters={f} from={applied.from} to={applied.to} extended />
                    <div className="flex items-center gap-2">
                        <FilterModal open={f.open} onOpenChange={f.onOpenChange} onApply={f.apply} onReset={f.reset} activeCount={f.activeCount}>
                            {isSuperAdmin && (
                                <FilterSelect
                                    label="الفرع"
                                    value={f.draft.branch}
                                    onChange={(v) => f.setField('branch', v)}
                                    allLabel="كل الفروع"
                                    options={branches!.map((b) => ({ value: b.id.toString(), label: b.name }))}
                                />
                            )}
                            <FilterSelect
                                label="الموظف"
                                value={f.draft.employee}
                                onChange={(v) => f.setField('employee', v)}
                                allLabel="كل الموظفين"
                                options={employees.map((e) => ({ value: e.id.toString(), label: e.name }))}
                            />
                            <FilterSelect
                                label="الحالة"
                                value={f.draft.status}
                                onChange={(v) => f.setField('status', v)}
                                allLabel="كل الحالات"
                                options={statuses}
                            />
                        </FilterModal>
                        <Button size="sm" onClick={openCreate}>
                            <Plus className="size-4" /> خطة حوافز
                        </Button>
                    </div>
                </div>

                <ActiveFilterChips chips={chips} />

                <DataTable columns={columns} data={plans.data} keyExtractor={(p) => p.id} />

                <TablePagination
                    currentPage={plans.meta.current_page as number}
                    totalPages={plans.meta.last_page as number}
                    totalItems={plans.meta.total as number}
                    onPageChange={(page) =>
                        router.reload({ data: { page } })
                    }
                />

                {/*
                    تاسك 74: الخصومات. بندٌ مستقلّ عمداً — لا يُنقص صفّاً في
                    commission_ledger ولا مكافأةً مصروفة، فكلاهما جدولٌ غير قابل
                    للتعديل. يُعرض هنا بجانب المستحق ولا يُعيد كتابة رقمٍ منشور.
                */}
                <div className="mt-10 mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h2 className="text-xl font-bold">الخصومات</h2>
                        <p className="text-muted-foreground text-sm">
                            حسمٌ تطبّقه الإدارة بسببه وقيمته. القيد لا يُعدَّل بعد تسجيله، وما سُجّل خطأً يُحذف.
                        </p>
                    </div>
                    <Button size="sm" variant="outline" onClick={() => setDeductionOpen(true)}>
                        <Minus className="size-4" /> تسجيل حسم
                    </Button>
                </div>

                <DataTable
                    columns={deductionColumns}
                    data={deductions.data}
                    keyExtractor={(d) => d.id}
                    footer={
                        <TableRow>
                            <TableCell className="font-bold whitespace-nowrap">الإجمالي — حسب التصفية</TableCell>
                            <TableCell />
                            {isSuperAdmin && <TableCell />}
                            <TableCell className="font-bold tabular-nums">{formatCurrency(deductionsTotal)}</TableCell>
                            <TableCell />
                            <TableCell />
                            <TableCell />
                        </TableRow>
                    }
                />

                <TablePagination
                    currentPage={deductions.meta.current_page as number}
                    totalPages={deductions.meta.last_page as number}
                    totalItems={deductions.meta.total as number}
                    onPageChange={(page) => router.reload({ data: { deductionsPage: page } })}
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

            <Dialog open={!!deletingDeduction} onOpenChange={(open) => !open && setDeletingDeduction(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد حذف الحسم</DialogTitle>
                        <DialogDescription>
                            هل أنت متأكد من حذف حسم «{deletingDeduction?.userName}» بمبلغ{' '}
                            {deletingDeduction ? formatCurrency(deletingDeduction.amount) : ''} بتاريخ{' '}
                            {deletingDeduction?.deductedAt}؟ لن يظهر بعدها في الكشوف ولا في الإجمالي.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeletingDeduction(null)}>
                            إلغاء
                        </Button>
                        <Button variant="destructive" onClick={handleDeleteDeduction}>
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

            <DeductionFormModal
                open={deductionOpen}
                onOpenChange={setDeductionOpen}
                employees={employees}
                reasons={deductionReasons}
            />
        </AppLayout>
    );
}
