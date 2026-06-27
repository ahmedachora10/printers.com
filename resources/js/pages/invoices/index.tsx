import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { FilterBar } from '@/components/filter-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type InvoiceFilters, type InvoiceListItem, type PaginatedInvoice } from '@/types/invoice';
import { Link, router } from '@inertiajs/react';
import { Eye, Printer } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'الفواتير', href: '/invoices' }];

const STATUS_COLORS: Record<string, string> = {
    paid: 'border-green-200 bg-green-50 text-green-700',
    due: 'border-red-200 bg-red-50 text-red-700',
    cancelled: 'border-border bg-muted/60 text-muted-foreground',
};

const TYPE_COLORS: Record<string, string> = {
    product: 'border-blue-200 bg-blue-50 text-blue-700',
    service: 'border-purple-200 bg-purple-50 text-purple-700',
};

interface Props {
    items: PaginatedInvoice;
    isSuperAdmin: boolean;
    availableTypes: { value: string; label: string }[];
    filters: InvoiceFilters;
}

export default function InvoicesIndex({ items, availableTypes, filters }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        type: filters.type ?? '',
        status: filters.status ?? '',
    });
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    function buildParams(s: string, fv: Record<string, string>, from: string, to: string) {
        return Object.fromEntries(
            Object.entries({ search: s, ...fv, date_from: from, date_to: to }).filter(([, v]) => v !== ''),
        );
    }

    const reload = (s: string, fv: Record<string, string>, from: string, to: string) => {
        router.get('/invoices', buildParams(s, fv, from, to), { preserveState: true, replace: true });
    };

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => reload(value, filterValues, dateFrom, dateTo), 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        reload(search, next, dateFrom, dateTo);
    };

    const handleDateChange = (which: 'from' | 'to', val: string) => {
        const from = which === 'from' ? val : dateFrom;
        const to = which === 'to' ? val : dateTo;
        if (which === 'from') setDateFrom(val);
        else setDateTo(val);
        reload(search, filterValues, from, to);
    };

    const handleClearAll = () => {
        setSearch('');
        setFilterValues({ type: '', status: '' });
        setDateFrom('');
        setDateTo('');
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get('/invoices', {}, { preserveState: true, replace: true });
    };

    const columns = useMemo<ColumnDef<InvoiceListItem>[]>(
        () => [
            {
                key: 'invoiceNumber',
                header: 'رقم الفاتورة',
                cell: (item) => (
                    <Link
                        href={`/invoices/${item.type}/${item.id}`}
                        className="font-medium text-foreground hover:underline"
                        dir="ltr"
                    >
                        {item.invoiceNumber}
                    </Link>
                ),
            },
            {
                key: 'type',
                header: 'النوع',
                cell: (item) => (
                    <Badge variant="outline" className={TYPE_COLORS[item.type]}>
                        {item.typeLabel}
                    </Badge>
                ),
            },
            {
                key: 'createdAt',
                header: 'التاريخ',
                cell: (item) => <span>{formatDate(item.createdAt)}</span>,
            },
            {
                key: 'customerName',
                header: 'العميل',
                cell: (item) => item.customerName ?? <span className="text-muted-foreground">عميل نقدي</span>,
            },
            {
                key: 'totalAmount',
                header: 'الإجمالي',
                cell: (item) => (
                    <span className="tabular-nums font-semibold" dir="ltr">
                        {formatCurrency(item.totalAmount)}
                    </span>
                ),
            },
            {
                key: 'status',
                header: 'الحالة',
                cell: (item) => (
                    <Badge variant="outline" className={STATUS_COLORS[item.status]}>
                        {item.statusLabel}
                    </Badge>
                ),
            },
            {
                key: 'actions',
                header: '',
                headerClassName: 'w-24',
                cell: (item) => (
                    <div className="flex items-center gap-1.5">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/invoices/${item.type}/${item.id}`}>
                                <Eye className="h-3.5 w-3.5" />
                            </Link>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <a href={`/invoices/${item.type}/${item.id}/print?format=a4`} target="_blank" rel="noreferrer">
                                <Printer className="h-3.5 w-3.5" />
                            </a>
                        </Button>
                    </div>
                ),
            },
        ],
        [],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">الفواتير</h1>
                </div>

                <div className="mb-6">
                    <FilterBar
                        searchable
                        searchPlaceholder="بحث برقم الفاتورة..."
                        searchValue={search}
                        onSearchChange={handleSearchChange}
                        filters={[
                            ...(availableTypes.length > 1
                                ? [{ key: 'type', placeholder: 'النوع', options: availableTypes }]
                                : []),
                            {
                                key: 'status',
                                placeholder: 'الحالة',
                                options: [
                                    { value: 'paid', label: 'مدفوعة' },
                                    { value: 'due', label: 'آجلة' },
                                    { value: 'cancelled', label: 'ملغاة' },
                                ],
                            },
                        ]}
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
                            </div>
                        }
                    />
                </div>

                <DataTable columns={columns} data={items.data} keyExtractor={(item) => `${item.type}-${item.id}`} />

                <TablePagination
                    currentPage={items.meta.current_page as number}
                    totalPages={items.meta.last_page as number}
                    totalItems={items.meta.total as number}
                    onPageChange={(page) => {
                        router.reload({ data: { page } });
                    }}
                />
            </div>
        </AppLayout>
    );
}
