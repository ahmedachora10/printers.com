import PoFormModal, { type PoProductOption } from '@/components/purchase-orders/po-form-modal';
import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { FilterBar } from '@/components/filter-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { PO_STATUS_BADGE, type PaginatedPurchaseOrder, type PurchaseOrder } from '@/types/purchase-order';
import { Link, router } from '@inertiajs/react';
import { Eye, Plus } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import inventory from '@/routes/inventory';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'المستودع', href: inventory.products.index().url },
    { title: 'أوامر الشراء', href: inventory.purchaseOrders.index().url },
];

interface Supplier {
    id: number;
    name: string;
}

interface StatusOption {
    value: string;
    label: string;
}

interface Props {
    items: PaginatedPurchaseOrder;
    suppliers: Supplier[];
    products: PoProductOption[];
    statuses: StatusOption[];
    filters: {
        search?: string;
        status?: string;
        supplier_id?: string;
    };
}

export default function PurchaseOrdersIndex({ items, suppliers, products, statuses, filters }: Props) {
    const [formOpen, setFormOpen] = useState(false);

    const columns = useMemo<ColumnDef<PurchaseOrder>[]>(
        () => [
            {
                key: 'poNumber',
                header: 'رقم الأمر',
                sortable: true,
                cell: (item) => (
                    <Link
                        href={inventory.purchaseOrders.show(item.id).url}
                        className="font-mono text-xs tracking-wider text-primary hover:underline"
                    >
                        {item.poNumber}
                    </Link>
                ),
            },
            {
                key: 'supplierName',
                header: 'المورد',
                cell: (item) => item.supplierName ?? '—',
            },
            {
                key: 'status',
                header: 'الحالة',
                cell: (item) => (
                    <Badge variant="outline" className={PO_STATUS_BADGE[item.status]}>
                        {item.statusLabel}
                    </Badge>
                ),
            },
            {
                key: 'total',
                header: 'الإجمالي',
                cell: (item) => (
                    <span dir="ltr" className="tabular-nums">
                        {formatCurrency(item.total ?? 0)}
                    </span>
                ),
            },
            {
                key: 'orderDate',
                header: 'تاريخ الأمر',
                cell: (item) => <span dir="ltr">{item.orderDate ?? '—'}</span>,
            },
            {
                key: 'actions',
                header: '',
                headerClassName: 'w-16',
                cell: (item) => (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={inventory.purchaseOrders.show(item.id).url}>
                            <Eye className="h-3.5 w-3.5" />
                        </Link>
                    </Button>
                ),
            },
        ],
        [],
    );

    const [search, setSearch] = useState(filters.search ?? '');
    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        status: filters.status ?? '',
        supplier_id: filters.supplier_id ?? '',
    });
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    const reload = (next: { search?: string; status?: string; supplier_id?: string }) => {
        router.get(
            inventory.purchaseOrders.index().url,
            {
                ...(next.search && { search: next.search }),
                ...(next.status && { status: next.status }),
                ...(next.supplier_id && { supplier_id: next.supplier_id }),
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
        setFilterValues({ status: '', supplier_id: '' });
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get(inventory.purchaseOrders.index().url, {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">أوامر الشراء</h1>
                </div>

                <div className="mb-6">
                    <FilterBar
                        searchable
                        searchPlaceholder="بحث برقم الأمر..."
                        searchValue={search}
                        onSearchChange={handleSearchChange}
                        filters={[
                            {
                                key: 'status',
                                placeholder: 'الحالة',
                                options: statuses.map((s) => ({ value: s.value, label: s.label })),
                            },
                            {
                                key: 'supplier_id',
                                placeholder: 'المورد',
                                options: suppliers.map((s) => ({ value: s.id.toString(), label: s.name })),
                            },
                        ]}
                        filterValues={filterValues}
                        onFilterChange={handleFilterChange}
                        onClearAll={handleClearAll}
                        actions={
                            <Button size="sm" onClick={() => setFormOpen(true)}>
                                <Plus className="size-4" /> أمر شراء جديد
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

            <PoFormModal
                open={formOpen}
                onOpenChange={setFormOpen}
                suppliers={suppliers}
                products={products}
            />
        </AppLayout>
    );
}
