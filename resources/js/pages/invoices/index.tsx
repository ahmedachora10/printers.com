import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import DeliveryBadge from '@/components/invoices/delivery-badge';
import InvoiceCustomerFields, { type InvoiceCustomerErrors, type InvoiceCustomerFormData } from '@/components/invoices/invoice-customer-fields';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import DateRangeBar from '@/components/reports/date-range-bar';
import { FilterSelect } from '@/components/reports/filter-fields';
import { FilterModal } from '@/components/reports/filter-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { INVOICE_STATUS_COLORS } from '@/lib/invoice';
import { cn, formatCurrency, formatDate } from '@/lib/utils';
import serviceInvoice from '@/routes/invoices/service';
import posService from '@/routes/pos/service';
import { type BreadcrumbItem } from '@/types';
import { type InvoiceFilters, type InvoiceListItem, type PaginatedInvoice } from '@/types/invoice';
import { Link, router } from '@inertiajs/react';
import { Eye, Info, Loader2, Pencil, Printer, Search, Undo2, UserPlus, X } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'الفواتير', href: '/invoices' }];

const INVOICES_URL = '/invoices';

/** Row actions are thumb-sized on touch and shrink back to the table scale at md. */
const ACTION_BUTTON = 'size-11 p-0 md:size-8';

const TYPE_COLORS: Record<string, string> = {
    product: 'border-blue-200 bg-blue-50 text-blue-700',
    service: 'border-purple-200 bg-purple-50 text-purple-700',
};

const STATUS_OPTIONS = [
    { value: 'paid', label: 'مدفوعة' },
    { value: 'partially_paid', label: 'مدفوعة جزئياً' },
    { value: 'due', label: 'آجلة' },
    { value: 'cancelled', label: 'ملغاة' },
    { value: 'returned', label: 'مرتجع' },
];

// موعد التسليم يخص فواتير الخدمات، فاختيار أيٍّ من الخيارين يُقصي فواتير
// المنتجات من النتيجة.
const DELIVERY_OPTIONS = [
    { value: 'today', label: 'تسليم اليوم' },
    { value: 'overdue', label: 'متأخر عن موعده' },
];

/** Modal-only filters — the search box and the date range apply on their own. */
const MODAL_KEYS = ['type', 'branch_id', 'status', 'delivery'];

interface Props {
    items: PaginatedInvoice;
    isSuperAdmin: boolean;
    availableTypes: { value: string; label: string }[];
    branches: { id: number; name: string }[] | null;
    filters: InvoiceFilters;
}

export default function InvoicesIndex({ items, isSuperAdmin, availableTypes, branches, filters }: Props) {
    // Filtering follows the report pages: the selects live in a modal, the date
    // range stays visible above the table, and applied values show as removable
    // chips. 'all' is the cleared value for the selects — useReportFilters drops
    // it from the query, so the controller keeps seeing an absent parameter.
    const defaults = useMemo<FilterValues>(
        () => ({ search: '', type: 'all', status: 'all', branch_id: 'all', delivery: 'all', date_from: '', date_to: '' }),
        [],
    );

    const applied: FilterValues = {
        search: filters.search ?? '',
        type: filters.type ?? 'all',
        status: filters.status ?? 'all',
        branch_id: filters.branch_id ?? 'all',
        delivery: filters.delivery ?? 'all',
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
    };
    const f = useReportFilters(INVOICES_URL, applied, defaults);

    const [search, setSearch] = useState(applied.search);
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

    // Typing searches on its own after a short pause; every other filter applies
    // on click, so the list never reloads mid-keystroke.
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

    const modalActiveCount = MODAL_KEYS.filter((key) => f.isActive(key)).length;

    const chips: FilterChip[] = [];
    // No chips for the search box or the date range — both stay visible above.
    if (f.isActive('type')) {
        const label = availableTypes.find((t) => t.value === applied.type)?.label ?? applied.type;
        chips.push({ key: 'type', label: `النوع: ${label}`, onRemove: () => f.remove('type') });
    }
    if (f.isActive('branch_id')) {
        const name = branches?.find((b) => b.id.toString() === applied.branch_id)?.name ?? applied.branch_id;
        chips.push({ key: 'branch_id', label: `الفرع: ${name}`, onRemove: () => f.remove('branch_id') });
    }
    if (f.isActive('status')) {
        const label = STATUS_OPTIONS.find((o) => o.value === applied.status)?.label ?? applied.status;
        chips.push({ key: 'status', label: `الحالة: ${label}`, onRemove: () => f.remove('status') });
    }
    if (f.isActive('delivery')) {
        const label = DELIVERY_OPTIONS.find((o) => o.value === applied.delivery)?.label ?? applied.delivery;
        chips.push({ key: 'delivery', label: `التسليم: ${label}`, onRemove: () => f.remove('delivery') });
    }

    const columns = useMemo<ColumnDef<InvoiceListItem>[]>(
        () => [
            {
                key: 'invoiceNumber',
                header: 'رقم الفاتورة',
                cell: (item) => (
                    <Link href={`/invoices/${item.type}/${item.id}`} className="text-foreground font-medium hover:underline" dir="ltr">
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
                key: 'deliveryAt',
                header: 'موعد التسليم',
                cell: (item) =>
                    item.deliveryAt ? (
                        <DeliveryBadge deliveryAt={item.deliveryAt} deliveryStatus={item.deliveryStatus} showLabel />
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    ),
            },
            {
                key: 'customerName',
                header: 'العميل',
                cell: (item) => (
                    <div className="group flex items-start justify-start gap-1.5">
                        <div className="min-w-0">
                            {item.customerName ? <span>{item.customerName}</span> : <span className="text-muted-foreground">عميل نقدي</span>}
                            {item.customerPhone && (
                                <div className="text-muted-foreground text-xs" dir="ltr">
                                    {item.customerPhone}
                                </div>
                            )}
                        </div>
                        {/* Hover reveals nothing on a touch screen, so the pencil stays
                            visible until md, where a pointer is likely. */}
                        {item.canEditCustomer && (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-9 shrink-0 focus-visible:opacity-100 md:size-6 md:opacity-0 md:group-hover:opacity-100"
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
                          cell: (item: InvoiceListItem) => item.branchName ?? <span className="text-muted-foreground">—</span>,
                      },
                  ]
                : []),
            {
                key: 'totalAmount',
                header: 'الإجمالي',
                cell: (item) => (
                    <span className="font-semibold tabular-nums" dir="ltr">
                        {formatCurrency(item.totalAmount)}
                    </span>
                ),
            },
            {
                key: 'remainingAmount',
                header: 'المتبقي',
                cell: (item) =>
                    item.remainingAmount > 0 ? (
                        <span className="font-semibold text-amber-700 tabular-nums dark:text-amber-400" dir="ltr">
                            {formatCurrency(item.remainingAmount)}
                        </span>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    ),
            },
            {
                key: 'status',
                header: 'الحالة',
                cell: (item) => {
                    const badge = (
                        <Badge variant="outline" className={INVOICE_STATUS_COLORS[item.status]}>
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
                        <Button variant="outline" size="sm" className={ACTION_BUTTON} asChild>
                            <Link href={`/invoices/${item.type}/${item.id}`} aria-label="عرض">
                                <Eye className="h-3.5 w-3.5" />
                            </Link>
                        </Button>
                        <Button variant="outline" size="sm" className={ACTION_BUTTON} asChild>
                            <a href={`/invoices/${item.type}/${item.id}/print?format=a4`} target="_blank" rel="noreferrer" aria-label="طباعة">
                                <Printer className="h-3.5 w-3.5" />
                            </a>
                        </Button>
                        {item.canEdit && (
                            <Button variant="outline" size="sm" className={ACTION_BUTTON} asChild>
                                <Link href={posService.edit(item.id).url} aria-label="تعديل">
                                    <Pencil className="h-3.5 w-3.5" />
                                </Link>
                            </Button>
                        )}
                        {item.canReturn && (
                            <Button
                                variant="outline"
                                size="sm"
                                className={cn(ACTION_BUTTON, 'text-destructive hover:text-destructive')}
                                aria-label="استرجاع الفاتورة"
                                title="استرجاع الفاتورة"
                                onClick={() => setReturnItem(item)}
                            >
                                <Undo2 className="h-3.5 w-3.5" />
                            </Button>
                        )}
                        {item.returnLocked && (
                            <Button variant="outline" size="sm" className={ACTION_BUTTON} disabled aria-label="مُرتجعة بالفعل" title="مُرتجعة بالفعل">
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
            <div className="p-4 md:p-6">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-bold md:text-2xl">الفواتير</h1>
                    <FilterModal
                        open={f.open}
                        onOpenChange={f.onOpenChange}
                        onApply={f.apply}
                        onReset={handleReset}
                        activeCount={modalActiveCount}
                        title="تصفية الفواتير"
                    >
                        {availableTypes.length > 1 && (
                            <FilterSelect
                                label="النوع"
                                value={f.draft.type}
                                onChange={(v) => f.setField('type', v)}
                                allLabel="كل الأنواع"
                                options={availableTypes}
                            />
                        )}
                        {branches && (
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
                            options={STATUS_OPTIONS}
                        />
                        <FilterSelect
                            label="موعد التسليم"
                            value={f.draft.delivery}
                            onChange={(v) => f.setField('delivery', v)}
                            allLabel="كل المواعيد"
                            options={DELIVERY_OPTIONS}
                        />
                    </FilterModal>
                </div>

                <Card className="mb-6 flex flex-wrap items-end justify-between gap-x-6 gap-y-4 border px-4 py-3.5">
                    <div className="space-y-1">
                        <Label htmlFor="invoice-search" className="text-muted-foreground text-xs">
                            بحث
                        </Label>
                        <div className="relative">
                            <Search className="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                            <Input
                                id="invoice-search"
                                value={search}
                                onChange={(e) => handleSearchChange(e.target.value)}
                                placeholder="رقم الفاتورة أو اسم الموظف..."
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
                            {editingItem && !editingItem.customerId ? ' — عميل نقدي غير مسجَّل، أدخل الاسم ورقم الجوال لتسجيله.' : ''}
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
