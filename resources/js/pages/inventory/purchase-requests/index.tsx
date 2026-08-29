import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { FilterBar } from '@/components/filter-bar';
import PrDetailModal from '@/components/purchase-requests/pr-detail-modal';
import PrFormModal from '@/components/purchase-requests/pr-form-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/utils';
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
import { Eye, Plus } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'طلبات الشراء', href: purchaseRequests.index().url }];

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
    filters: {
        search?: string;
        status?: string;
    };
}

export default function PurchaseRequestsIndex({ items, products, suppliers, branches, statuses, filters }: Props) {
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
            {
                key: 'branchName',
                header: 'الفرع',
                cell: (item) => item.branchName ?? '—',
            },
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
        [],
    );

    const [search, setSearch] = useState(filters.search ?? '');
    const [filterValues, setFilterValues] = useState<Record<string, string>>({ status: filters.status ?? '' });
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    const reload = (next: { search?: string; status?: string }) => {
        router.get(
            purchaseRequests.index().url,
            {
                ...(next.search && { search: next.search }),
                ...(next.status && { status: next.status }),
            },
            { preserveState: true, replace: true },
        );
    };

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => reload({ search: value, ...filterValues }), 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        reload({ search, ...next });
    };

    const handleClearAll = () => {
        setSearch('');
        setFilterValues({ status: '' });
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get(purchaseRequests.index().url, {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">طلبات الشراء الداخلية</h1>
                </div>

                <div className="mb-6">
                    <FilterBar
                        searchable
                        searchPlaceholder="بحث باسم الصنف..."
                        searchValue={search}
                        onSearchChange={handleSearchChange}
                        filters={[
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
                            <Button size="sm" onClick={() => setFormOpen(true)}>
                                <Plus className="size-4" /> طلب شراء جديد
                            </Button>
                        }
                    />
                </div>

                <DataTable columns={columns} data={items.data} keyExtractor={(item) => item.id} />

                <TablePagination
                    currentPage={items.meta.current_page as number}
                    totalPages={items.meta.last_page as number}
                    totalItems={items.meta.total as number}
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
