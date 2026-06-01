import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { FilterBar } from '@/components/filter-bar';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/utils';
import inventory from '@/routes/inventory';
import { type BreadcrumbItem } from '@/types';
import { type PaginatedStockMovement, type StockMovement } from '@/types/stock-movement';
import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'المستودع', href: inventory.products.index().url },
    { title: 'تحركات المخزون', href: inventory.stockMovements.index().url },
];

interface ProductOption {
    id: number;
    name: string;
}

interface TypeOption {
    value: string;
    label: string;
}

interface Props {
    items: PaginatedStockMovement;
    products: ProductOption[];
    types: TypeOption[];
    filters: {
        product_id?: string;
        type?: string;
        from?: string;
        to?: string;
    };
}

export default function StockMovementsIndex({ items, products, types, filters }: Props) {
    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        product_id: filters.product_id ?? '',
        type: filters.type ?? '',
    });
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');

    function reload(next: Record<string, string>) {
        router.get(
            inventory.stockMovements.index().url,
            Object.fromEntries(Object.entries(next).filter(([, v]) => v)),
            { preserveState: true, replace: true },
        );
    }

    const currentQuery = () => ({
        product_id: filterValues.product_id,
        type: filterValues.type,
        from,
        to,
    });

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        reload({ ...currentQuery(), [key]: val });
    };

    const handleDateChange = (key: 'from' | 'to', val: string) => {
        if (key === 'from') setFrom(val);
        else setTo(val);
        reload({ ...currentQuery(), [key]: val });
    };

    const handleClearAll = () => {
        setFilterValues({ product_id: '', type: '' });
        setFrom('');
        setTo('');
        router.get(inventory.stockMovements.index().url, {}, { preserveState: true, replace: true });
    };

    const columns = useMemo<ColumnDef<StockMovement>[]>(
        () => [
            {
                key: 'createdAt',
                header: 'التاريخ',
                cell: (item) => <span className="text-sm text-muted-foreground">{item.createdAt}</span>,
            },
            {
                key: 'productName',
                header: 'المنتج',
                cell: (item) => (
                    <div className="flex flex-col">
                        <span className="font-medium">{item.productName ?? '—'}</span>
                        <span className="font-mono text-xs tracking-widest text-muted-foreground">{item.sku}</span>
                    </div>
                ),
            },
            {
                key: 'type',
                header: 'النوع',
                cell: (item) => (
                    <Badge
                        variant="outline"
                        className={
                            item.qty >= 0
                                ? 'border-green-200 bg-green-50 text-green-700'
                                : 'border-red-200 bg-red-50 text-red-700'
                        }
                    >
                        {item.typeLabel}
                    </Badge>
                ),
            },
            {
                key: 'qty',
                header: 'الكمية',
                cell: (item) => (
                    <span
                        dir="ltr"
                        className={
                            item.qty >= 0
                                ? 'font-semibold tabular-nums text-green-600'
                                : 'font-semibold tabular-nums text-red-600'
                        }
                    >
                        {item.qty > 0 ? `+${item.qty}` : item.qty}
                    </span>
                ),
            },
            {
                key: 'unitCost',
                header: 'سعر التكلفة',
                cell: (item) =>
                    item.unitCost !== null ? (
                        <span dir="ltr" className="tabular-nums">
                            {formatCurrency(item.unitCost)}
                        </span>
                    ) : (
                        '—'
                    ),
            },
            {
                key: 'createdByName',
                header: 'بواسطة',
                cell: (item) => item.createdByName ?? '—',
            },
            {
                key: 'notes',
                header: 'ملاحظات',
                cell: (item) => <span className="text-sm text-muted-foreground">{item.notes ?? '—'}</span>,
            },
        ],
        [],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">تحركات المخزون</h1>
                </div>

                <div className="mb-6">
                    <FilterBar
                        filters={[
                            {
                                key: 'product_id',
                                placeholder: 'المنتج',
                                width: 'w-48',
                                options: products.map((p) => ({ value: p.id.toString(), label: p.name })),
                            },
                            {
                                key: 'type',
                                placeholder: 'النوع',
                                options: types.map((t) => ({ value: t.value, label: t.label })),
                            },
                        ]}
                        filterValues={filterValues}
                        onFilterChange={handleFilterChange}
                        onClearAll={handleClearAll}
                        actions={
                            <div className="flex items-center gap-2">
                                <Input
                                    type="date"
                                    value={from}
                                    onChange={(e) => handleDateChange('from', e.target.value)}
                                    className="h-9 w-40 text-sm"
                                    aria-label="من تاريخ"
                                />
                                <span className="text-muted-foreground">—</span>
                                <Input
                                    type="date"
                                    value={to}
                                    onChange={(e) => handleDateChange('to', e.target.value)}
                                    className="h-9 w-40 text-sm"
                                    aria-label="إلى تاريخ"
                                />
                            </div>
                        }
                    />
                </div>

                <DataTable columns={columns} data={items.data} keyExtractor={(item) => item.id} />

                <TablePagination
                    currentPage={items.meta.current_page as number}
                    totalPages={items.meta.last_page as number}
                    totalItems={items.meta.total as number}
                    onPageChange={(page) => {
                        router.get(
                            inventory.stockMovements.index({ query: { page } }).url,
                            {},
                            { preserveState: true, preserveScroll: true },
                        );
                    }}
                />
            </div>
        </AppLayout>
    );
}
