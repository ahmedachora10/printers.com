import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { FilterBar } from '@/components/filter-bar';
import InvoiceCustomerFields, { type InvoiceCustomerErrors, type InvoiceCustomerFormData } from '@/components/invoices/invoice-customer-fields';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate } from '@/lib/utils';
import serviceInvoice from '@/routes/invoices/service';
import posService from '@/routes/pos/service';
import { type BreadcrumbItem } from '@/types';
import { type InvoiceFilters, type InvoiceListItem, type PaginatedInvoice } from '@/types/invoice';
import { Link, router } from '@inertiajs/react';
import { Eye, Info, Loader2, Pencil, Printer, Undo2, UserPlus } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'الفواتير', href: '/invoices' }];

const STATUS_COLORS: Record<string, string> = {
    paid: 'border-green-200 bg-green-50 text-green-700',
    due: 'border-red-200 bg-red-50 text-red-700',
    cancelled: 'border-border bg-muted/60 text-muted-foreground',
    returned: 'border-red-300 bg-red-100 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-300',
};

const TYPE_COLORS: Record<string, string> = {
    product: 'border-blue-200 bg-blue-50 text-blue-700',
    service: 'border-purple-200 bg-purple-50 text-purple-700',
};

interface Props {
    items: PaginatedInvoice;
    isSuperAdmin: boolean;
    availableTypes: { value: string; label: string }[];
    branches: { id: number; name: string }[] | null;
    filters: InvoiceFilters;
}

export default function InvoicesIndex({ items, isSuperAdmin, availableTypes, branches, filters }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        type: filters.type ?? '',
        status: filters.status ?? '',
        branch_id: filters.branch_id ?? '',
    });
    const [dateFrom, setDateFrom] = useState(filters.date_from ?? '');
    const [dateTo, setDateTo] = useState(filters.date_to ?? '');
    const [returnItem, setReturnItem] = useState<InvoiceListItem | null>(null);
    const [returnReason, setReturnReason] = useState('');
    const [returning, setReturning] = useState(false);
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    // Customer name/phone/tax editing — service invoices only, gated by
    // item.canEditCustomer. Reuses the review-queue update-customer endpoint,
    // which also registers a new customer for cash rows (no customerId). The
    // form opens in a modal via the shared InvoiceCustomerFields component.
    const [editingItem, setEditingItem] = useState<InvoiceListItem | null>(null);
    const [editData, setEditData] = useState<InvoiceCustomerFormData>({ full_name: '', phone: '', tax_number: '' });
    const [editErrors, setEditErrors] = useState<InvoiceCustomerErrors>({});
    const [savingCustomer, setSavingCustomer] = useState(false);

    function openCustomerEditor(item: InvoiceListItem) {
        setEditingItem(item);
        setEditData({
            full_name: item.customerName ?? '',
            phone: item.customerPhone ?? '',
            tax_number: item.customerTaxNumber ?? '',
        });
        setEditErrors({});
    }

    function saveCustomer() {
        if (!editingItem) return;
        setSavingCustomer(true);
        router.patch(
            serviceInvoice.updateCustomer(editingItem.id).url,
            { full_name: editData.full_name.trim(), phone: editData.phone.trim(), tax_number: editData.tax_number.trim() },
            {
                preserveScroll: true,
                onError: (e) => setEditErrors({ full_name: e.full_name, phone: e.phone, tax_number: e.tax_number }),
                onSuccess: () => {
                    setEditingItem(null);
                    setEditErrors({});
                    toast.success('تم تحديث بيانات العميل.');
                },
                onFinish: () => setSavingCustomer(false),
            },
        );
    }

    function confirmReturn() {
        if (!returnItem) return;
        setReturning(true);
        router.post(
            posService.return(returnItem.id).url,
            { reason: returnReason.trim() },
            {
                preserveScroll: true,
                onError: (e) => toast.error((Object.values(e)[0] as string) ?? 'تعذّر استرجاع الفاتورة.'),
                onFinish: () => {
                    setReturning(false);
                    setReturnItem(null);
                    setReturnReason('');
                },
            },
        );
    }

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
        setFilterValues({ type: '', status: '', branch_id: '' });
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
                    <div className="flex flex-col items-start gap-1.5">
                        <Badge variant="outline" className={TYPE_COLORS[item.type]}>
                            {item.typeLabel}
                        </Badge>
                        {item.serviceNames.length > 0 && (
                            <div className="flex flex-wrap gap-1">
                                {item.serviceNames.map((name) => (
                                    <Badge key={name} variant="outline" className="border-border bg-muted/60 text-muted-foreground">
                                        {name}
                                    </Badge>
                                ))}
                            </div>
                        )}
                    </div>
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
                cell: (item) => (
                    <div className="group flex items-start justify-start gap-1.5">
                        <div className="min-w-0">
                            {item.customerName ? (
                                <span>{item.customerName}</span>
                            ) : (
                                <span className="text-muted-foreground">عميل نقدي</span>
                            )}
                            {item.customerPhone && (
                                <div className="text-xs text-muted-foreground" dir="ltr">
                                    {item.customerPhone}
                                </div>
                            )}
                        </div>
                        {item.canEditCustomer && (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="h-6 w-6 shrink-0 opacity-0 group-hover:opacity-100 focus-visible:opacity-100"
                                aria-label={item.customerId ? 'تعديل بيانات العميل' : 'إضافة عميل'}
                                title={item.customerId ? 'تعديل بيانات العميل' : 'إضافة عميل'}
                                onClick={() => openCustomerEditor(item)}
                            >
                                {item.customerId ? <Pencil className="h-3.5 w-3.5" /> : <UserPlus className="h-3.5 w-3.5" />}
                            </Button>
                        )}
                    </div>
                ),
            },
            {
                key: 'employeeName',
                header: 'منشئ الفاتورة',
                cell: (item) => item.employeeName ?? <span className="text-muted-foreground">—</span>,
            },
            ...(isSuperAdmin
                ? [
                      {
                          key: 'branchName',
                          header: 'الفرع',
                          cell: (item: InvoiceListItem) =>
                              item.branchName ?? <span className="text-muted-foreground">—</span>,
                      },
                  ]
                : []),
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
                cell: (item) => {
                    const badge = (
                        <Badge variant="outline" className={STATUS_COLORS[item.status]}>
                            {item.statusLabel}
                        </Badge>
                    );

                    // A rejected invoice carries its reason on the badge, so the
                    // employee sees why without opening the invoice.
                    if (item.status !== 'cancelled' || !item.cancellationReason) {
                        return badge;
                    }

                    return (
                        <TooltipProvider delayDuration={100}>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span className="inline-flex cursor-help items-center gap-1">
                                        {badge}
                                        <Info className="text-muted-foreground h-3.5 w-3.5" aria-hidden />
                                        <span className="sr-only">سبب الإلغاء: {item.cancellationReason}</span>
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent className="max-w-xs whitespace-pre-line">سبب الإلغاء: {item.cancellationReason}</TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    );
                },
            },
            {
                key: 'actions',
                header: '',
                headerClassName: 'w-36',
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
                        {item.canEdit && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={posService.edit(item.id).url} aria-label="تعديل">
                                    <Pencil className="h-3.5 w-3.5" />
                                </Link>
                            </Button>
                        )}
                        {item.canReturn && (
                            <Button
                                variant="outline"
                                size="sm"
                                className="text-destructive hover:text-destructive"
                                aria-label="استرجاع الفاتورة"
                                title="استرجاع الفاتورة"
                                onClick={() => setReturnItem(item)}
                            >
                                <Undo2 className="h-3.5 w-3.5" />
                            </Button>
                        )}
                        {item.returnLocked && (
                            <Button variant="outline" size="sm" disabled aria-label="مُرتجعة بالفعل" title="مُرتجعة بالفعل">
                                <Undo2 className="h-3.5 w-3.5" />
                            </Button>
                        )}
                    </div>
                ),
            },
        ],
        [isSuperAdmin],
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
                        searchPlaceholder="بحث برقم الفاتورة أو اسم الموظف..."
                        searchValue={search}
                        onSearchChange={handleSearchChange}
                        filters={[
                            ...(availableTypes.length > 1
                                ? [{ key: 'type', placeholder: 'النوع', options: availableTypes }]
                                : []),
                            ...(branches
                                ? [
                                      {
                                          key: 'branch_id',
                                          placeholder: 'الفرع',
                                          options: branches.map((b) => ({ value: b.id.toString(), label: b.name })),
                                      },
                                  ]
                                : []),
                            {
                                key: 'status',
                                placeholder: 'الحالة',
                                options: [
                                    { value: 'paid', label: 'مدفوعة' },
                                    { value: 'due', label: 'آجلة' },
                                    { value: 'cancelled', label: 'ملغاة' },
                                    { value: 'returned', label: 'مرتجع' },
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

                <DataTable
                    columns={columns}
                    data={items.data}
                    keyExtractor={(item) => `${item.type}-${item.id}`}
                    rowClassName={(item) =>
                        item.status === 'returned' ? 'bg-red-50 hover:bg-red-100/70 dark:bg-red-950/30 dark:hover:bg-red-950/50' : undefined
                    }
                />

                <TablePagination
                    currentPage={items.meta.current_page as number}
                    totalPages={items.meta.last_page as number}
                    totalItems={items.meta.total as number}
                    onPageChange={(page) => {
                        router.reload({ data: { page } });
                    }}
                />
            </div>

            <Dialog open={!!editingItem} onOpenChange={(open) => !open && !savingCustomer && setEditingItem(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editingItem?.customerId ? 'تعديل بيانات العميل' : 'إضافة عميل للفاتورة'}</DialogTitle>
                        <DialogDescription>
                            الفاتورة {editingItem?.invoiceNumber}
                            {editingItem && !editingItem.customerId
                                ? ' — عميل نقدي غير مسجَّل، أدخل الاسم ورقم الجوال لتسجيله.'
                                : ''}
                        </DialogDescription>
                    </DialogHeader>
                    <InvoiceCustomerFields
                        idPrefix="invoice-customer"
                        data={editData}
                        onChange={(field, value) => setEditData((prev) => ({ ...prev, [field]: value }))}
                        errors={editErrors}
                        disabled={savingCustomer}
                        autoFocus
                    />
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setEditingItem(null)} disabled={savingCustomer}>
                            إلغاء
                        </Button>
                        <Button onClick={saveCustomer} disabled={savingCustomer}>
                            {savingCustomer && <Loader2 className="size-4 animate-spin" />} حفظ
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={!!returnItem} onOpenChange={(open) => !open && !returning && setReturnItem(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>استرجاع الفاتورة</DialogTitle>
                        <DialogDescription>
                            تبقى الفاتورة {returnItem?.invoiceNumber} ظاهرة في القوائم بحالة «مرتجع» ولا تُحذف
                            {returnItem?.status === 'paid'
                                ? '، ويُسجَّل لها مرتجع بكامل المتبقي مع عكس العمولة غير المدفوعة وسحب نقاط الولاء المكتسبة واسترجاع أي نقاط مستبدلة.'
                                : '، مع عكس العمولة غير المدفوعة واسترجاع أي نقاط مستبدلة.'}{' '}
                            لا يمكن التراجع عن هذا الإجراء.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-1">
                        <label htmlFor="return-reason" className="text-sm font-medium">
                            سبب الاسترجاع <span className="text-muted-foreground font-normal">(اختياري)</span>
                        </label>
                        <textarea
                            id="return-reason"
                            rows={3}
                            value={returnReason}
                            onChange={(e) => setReturnReason(e.target.value)}
                            placeholder="سبب استرجاع الفاتورة..."
                            disabled={returning}
                            className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[80px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                        />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setReturnItem(null)} disabled={returning}>
                            تراجع
                        </Button>
                        <Button variant="destructive" onClick={confirmReturn} disabled={returning}>
                            <Undo2 className="size-4" /> تأكيد الاسترجاع
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
