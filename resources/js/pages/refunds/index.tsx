import RefundFormModal from '@/components/refunds/refund-form-modal';
import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { FilterBar } from '@/components/filter-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/utils';
import refunds from '@/routes/refunds';
import { type BreadcrumbItem } from '@/types';
import { type PaginatedRefund, type RefundFilters, type RefundListItem } from '@/types/refund';
import { Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'المرتجعات', href: '/refunds' }];

const TYPE_COLORS: Record<string, string> = {
    product: 'border-blue-200 bg-blue-50 text-blue-700',
    service: 'border-purple-200 bg-purple-50 text-purple-700',
};

interface Props {
    items: PaginatedRefund;
    sourceTypes: { value: string; label: string }[];
    filters: RefundFilters;
}

export default function RefundsIndex({ items, sourceTypes, filters }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        source_type: filters.source_type ?? '',
    });
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');

    function buildParams(fv: Record<string, string>, from: string, to: string) {
        return Object.fromEntries(
            Object.entries({ ...fv, date_from: from, date_to: to }).filter(([, v]) => v !== ''),
        );
    }

    const reload = (fv: Record<string, string>, from: string, to: string) => {
        router.get(refunds.index().url, buildParams(fv, from, to), { preserveState: true, replace: true });
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        reload(next, dateFrom, dateTo);
    };

    const handleDateChange = (which: 'from' | 'to', val: string) => {
        const from = which === 'from' ? val : dateFrom;
        const to = which === 'to' ? val : dateTo;
        if (which === 'from') setDateFrom(val);
        else setDateTo(val);
        reload(filterValues, from, to);
    };

    const handleClearAll = () => {
        setFilterValues({ source_type: '' });
        setDateFrom('');
        setDateTo('');
        router.get(refunds.index().url, {}, { preserveState: true, replace: true });
    };

    const columns = useMemo<ColumnDef<RefundListItem>[]>(
        () => [
            {
                key: 'invoiceNumber',
                header: 'رقم الفاتورة',
                cell: (item) =>
                    item.invoiceId && item.invoiceNumber ? (
                        <Link
                            href={`/invoices/${item.sourceType}/${item.invoiceId}`}
                            className="font-medium text-foreground hover:underline"
                            dir="ltr"
                        >
                            {item.invoiceNumber}
                        </Link>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    ),
            },
            {
                key: 'sourceType',
                header: 'النوع',
                cell: (item) => (
                    <Badge variant="outline" className={TYPE_COLORS[item.sourceType]}>
                        {item.sourceTypeLabel}
                    </Badge>
                ),
            },
            {
                key: 'amount',
                header: 'المبلغ',
                cell: (item) => (
                    <span className="font-semibold tabular-nums text-destructive" dir="ltr">
                        {formatCurrency(item.amount)}
                    </span>
                ),
            },
            {
                key: 'reason',
                header: 'السبب',
                cell: (item) => (
                    <span className="block max-w-xs truncate text-sm text-muted-foreground" title={item.reason}>
                        {item.reason}
                    </span>
                ),
            },
            {
                key: 'stockReversed',
                header: 'المخزون',
                cell: (item) =>
                    item.stockReversed ? (
                        <Badge variant="outline" className="border-green-200 bg-green-50 text-green-700">
                            تم الإرجاع
                        </Badge>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    ),
            },
            {
                key: 'userName',
                header: 'بواسطة',
                cell: (item) => <span className="text-sm">{item.userName ?? '—'}</span>,
            },
            {
                key: 'createdAt',
                header: 'التاريخ',
                cell: (item) => <span className="text-sm text-muted-foreground" dir="ltr">{item.createdAt ?? '—'}</span>,
            },
        ],
        [],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">المرتجعات</h1>
                </div>

                <div className="mb-6">
                    <FilterBar
                        filters={[{ key: 'source_type', placeholder: 'النوع', options: sourceTypes }]}
                        filterValues={filterValues}
                        onFilterChange={handleFilterChange}
                        onClearAll={handleClearAll}
                        actions={
                            <div className="flex items-center gap-2">
                                <Input
                                    type="date"
                                    value={dateFrom}
                                    onChange={(e) => handleDateChange('from', e.target.value)}
                                    className="h-9 w-40 text-sm"
                                    aria-label="من تاريخ"
                                />
                                <span className="text-muted-foreground">—</span>
                                <Input
                                    type="date"
                                    value={dateTo}
                                    onChange={(e) => handleDateChange('to', e.target.value)}
                                    className="h-9 w-40 text-sm"
                                    aria-label="إلى تاريخ"
                                />
                                <Button size="sm" onClick={() => setFormOpen(true)}>
                                    <Plus className="size-4" /> إنشاء مرتجع
                                </Button>
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
                        router.reload({ data: { page } });
                    }}
                />
            </div>

            <RefundFormModal key={formOpen ? 'open' : 'closed'} open={formOpen} onOpenChange={setFormOpen} />
        </AppLayout>
    );
}
