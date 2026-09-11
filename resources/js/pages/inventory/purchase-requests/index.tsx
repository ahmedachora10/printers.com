import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import PrDetailModal from '@/components/purchase-requests/pr-detail-modal';
import PrFormModal from '@/components/purchase-requests/pr-form-modal';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import DateRangeBar from '@/components/reports/date-range-bar';
import { FilterSelect } from '@/components/reports/filter-fields';
import { FilterModal } from '@/components/reports/filter-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { cn, formatCurrency } from '@/lib/utils';
import purchaseRequests from '@/routes/purchase-requests';
import { type BreadcrumbItem } from '@/types';
import {
    PR_STATUS_BADGE,
    type PaginatedPurchaseRequest,
    type PrBranchOption,
    type PrProductOption,
    type PrSupplierOption,
    type PurchaseRequest,
} from '@/types/purchase-request';
import { router } from '@inertiajs/react';
import { Eye, Plus, Search, X } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'طلبات الشراء', href: purchaseRequests.index().url }];

// تاسك 103 — على نمط شاشة الفواتير: القوائم في نافذة، والمدى والبحث ظاهران.
const DEFAULTS: FilterValues = { search: '', status: 'all', requested_by: 'all', branch_id: 'all', date_from: '', date_to: '' };
const MODAL_KEYS = ['status', 'requested_by', 'branch_id'];

interface StatusOption {
    value: string;
    label: string;
}

interface Props {
    items: PaginatedPurchaseRequest;
    products: PrProductOption[];
    suppliers: PrSupplierOption[];
    branches: PrBranchOption[];
    statuses: StatusOption[];
    /** مقدّمو الطلبات المرئية — فارغة للموظف والمحاسب. */
    requesters: { id: number; name: string }[];
    filters: Partial<Record<'search' | 'status' | 'requested_by' | 'branch_id' | 'date_from' | 'date_to', string | null>>;
}

export default function PurchaseRequestsIndex({ items, products, suppliers, branches, statuses, requesters, filters }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [selected, setSelected] = useState<PurchaseRequest | null>(null);

    const columns = useMemo<ColumnDef<PurchaseRequest>[]>(
        () => [
            {
                key: 'id',
                header: 'رقم الطلب',
                cell: (item) => <span className="font-mono text-xs tracking-wider">{item.id}</span>,
            },
            {
                key: 'requestedByName',
                header: 'مقدّم الطلب',
                cell: (item) => item.requestedByName ?? '—',
            },
            // مدير الفرع كان يرى اسم فرعه وحده في كل صفّ (تاسك 102).
            ...(branches.length > 0
                ? [{ key: 'branchName', header: 'الفرع', cell: (item: PurchaseRequest) => item.branchName ?? '—' }]
                : []),
            {
                key: 'linesCount',
                header: 'عدد الأصناف',
                cell: (item) => <span dir="ltr">{item.linesCount ?? 0}</span>,
            },
            {
                key: 'estimatedTotal',
                header: 'الإجمالي التقديري',
                cell: (item) => (
                    <span dir="ltr" className="tabular-nums">
                        {formatCurrency(item.estimatedTotal ?? 0)}
                    </span>
                ),
            },
            {
                key: 'status',
                header: 'الحالة',
                cell: (item) => (
                    <Badge variant="outline" className={PR_STATUS_BADGE[item.status]}>
                        {item.statusLabel}
                    </Badge>
                ),
            },
            {
                key: 'createdAt',
                header: 'تاريخ الطلب',
                cell: (item) => <span dir="ltr">{item.createdAt ?? '—'}</span>,
            },
            {
                key: 'actions',
                header: '',
                headerClassName: 'w-16',
                cell: (item) => (
                    <Button variant="outline" size="sm" onClick={() => setSelected(item)}>
                        <Eye className="h-3.5 w-3.5" />
                    </Button>
                ),
            },
        ],
        [branches],
    );

    const applied: FilterValues = Object.fromEntries(Object.entries(DEFAULTS).map(([key, value]) => [key, filters[key as keyof typeof filters] || value]));
    const f = useReportFilters(purchaseRequests.index().url, applied, DEFAULTS);

    const [search, setSearch] = useState(applied.search);
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => f.replace('search', value), 400);
    };

    const handleReset = () => {
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        setSearch('');
        f.reset();
    };

    const chips: FilterChip[] = [];
    if (f.isActive('status')) {
        const label = statuses.find((s) => s.value === applied.status)?.label ?? applied.status;
        chips.push({ key: 'status', label: `الحالة: ${label}`, onRemove: () => f.remove('status') });
    }
    if (f.isActive('requested_by')) {
        const name = requesters.find((r) => r.id.toString() === applied.requested_by)?.name ?? applied.requested_by;
        chips.push({ key: 'requested_by', label: `مقدّم الطلب: ${name}`, onRemove: () => f.remove('requested_by') });
    }
    if (f.isActive('branch_id')) {
        const name = branches.find((b) => b.id.toString() === applied.branch_id)?.name ?? applied.branch_id;
        chips.push({ key: 'branch_id', label: `الفرع: ${name}`, onRemove: () => f.remove('branch_id') });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-4 md:p-6">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-bold md:text-2xl">طلبات الشراء الداخلية</h1>
                    <div className="flex items-center gap-2">
                        <FilterModal
                            open={f.open}
                            onOpenChange={f.onOpenChange}
                            onApply={f.apply}
                            onReset={handleReset}
                            activeCount={MODAL_KEYS.filter((key) => f.isActive(key)).length}
                            title="تصفية طلبات الشراء"
                        >
                            {requesters.length > 0 && (
                                <FilterSelect
                                    label="مقدّم الطلب"
                                    value={f.draft.requested_by}
                                    onChange={(v) => f.setField('requested_by', v)}
                                    allLabel="كل المقدّمين"
                                    searchable
                                    options={requesters.map((r) => ({ value: r.id.toString(), label: r.name }))}
                                />
                            )}
                            {branches.length > 0 && (
                                <FilterSelect
                                    label="الفرع"
                                    value={f.draft.branch_id}
                                    onChange={(v) => f.setField('branch_id', v)}
                                    allLabel="كل الفروع"
                                    options={branches.map((b) => ({ value: b.id.toString(), label: b.name }))}
                                />
                            )}
                            <FilterSelect
                                label="الحالة"
                                value={f.draft.status}
                                onChange={(v) => f.setField('status', v)}
                                allLabel="كل الحالات"
                                options={statuses}
                            />
                        </FilterModal>
                        <Button size="sm" onClick={() => setFormOpen(true)}>
                            <Plus className="size-4" /> طلب شراء جديد
                        </Button>
                    </div>
                </div>

                <Card className="mb-6 flex flex-wrap items-end justify-between gap-x-6 gap-y-4 border px-4 py-3.5">
                    <div className="space-y-1">
                        <Label htmlFor="pr-search" className="text-muted-foreground text-xs">
                            بحث
                        </Label>
                        <div className="relative">
                            <Search className="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                            <Input
                                id="pr-search"
                                value={search}
                                onChange={(e) => handleSearchChange(e.target.value)}
                                placeholder="بحث باسم الصنف..."
                                className={cn('h-8 w-full ps-9 pe-8 text-sm sm:w-72', search && 'border-primary/40 bg-primary/5')}
                            />
                            {search && (
                                <button
                                    type="button"
                                    onClick={() => handleSearchChange('')}
                                    className="text-muted-foreground hover:text-foreground absolute end-2.5 top-1/2 -translate-y-1/2 rounded p-0.5 transition-colors"
                                    aria-label="مسح البحث"
                                >
                                    <X className="size-3.5" />
                                </button>
                            )}
                        </div>
                    </div>

                    <DateRangeBar filters={f} from={applied.date_from} to={applied.date_to} fromKey="date_from" toKey="date_to" extended />
                </Card>

                <ActiveFilterChips chips={chips} />

                <DataTable columns={columns} data={items.data} keyExtractor={(item) => item.id} />

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

            <PrFormModal open={formOpen} onOpenChange={setFormOpen} products={products} branches={branches} />

            <PrDetailModal request={selected} onOpenChange={() => setSelected(null)} suppliers={suppliers} products={products} />
        </AppLayout>
    );
}
