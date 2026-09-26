import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import DeliveryBadge from '@/components/invoices/delivery-badge';
import InvoiceCustomerFields, { type InvoiceCustomerErrors, type InvoiceCustomerFormData } from '@/components/invoices/invoice-customer-fields';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import DateRangeBar from '@/components/reports/date-range-bar';
import FilterSearch from '@/components/reports/filter-search';
import { FilterField, FilterSelect } from '@/components/reports/filter-fields';
import { FilterModal } from '@/components/reports/filter-modal';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { INVOICE_STATUS_COLORS } from '@/lib/invoice';
import { cn, formatCurrency, formatDate } from '@/lib/utils';
import serviceInvoice from '@/routes/invoices/service';
import posProduct from '@/routes/pos/product';
import posService from '@/routes/pos/service';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type InvoiceFilters, type InvoiceListItem, type PaginatedInvoice } from '@/types/invoice';
import { Link, router, usePage } from '@inertiajs/react';
import { CheckCircle2, Eye, Info, Loader2, MessageSquare, MoreHorizontal, PackageCheck, Pencil, Printer, Undo2, UserPlus } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'الفواتير', href: '/invoices' }];

const INVOICES_URL = '/invoices';

/** Row actions are thumb-sized on touch and shrink back to the table scale at md. */
const ACTION_BUTTON = 'size-11 p-0 md:size-8';

const TYPE_COLORS: Record<string, string> = {
    product: 'border-blue-200 bg-blue-50 text-blue-700',
    service: 'border-purple-200 bg-purple-50 text-purple-700',
};

// موعد التسليم يخص فواتير الخدمات، فاختيار أيٍّ من الخيارين يُقصي فواتير
// المنتجات من النتيجة.
const DELIVERY_OPTIONS = [
    { value: 'today', label: 'تسليم اليوم' },
    { value: 'overdue', label: 'متأخر عن موعده' },
    { value: 'delivered', label: 'تم التسليم' },
];

/** Modal-only filters — the search box and the date range apply on their own. */
const MODAL_KEYS = ['type', 'branch_id', 'status', 'delivery', 'user_id', 'payment_method_id', 'branch_service_id', 'time_from', 'time_to'];

interface NamedOption {
    id: number;
    name: string;
    /** null = عامّ لكل الفروع (طرق الدفع) أو بلا فرع (السوبر أدمن). */
    branchId: number | null;
}

interface Props {
    items: PaginatedInvoice;
    isSuperAdmin: boolean;
    availableTypes: { value: string; label: string }[];
    branches: { id: number; name: string }[] | null;
    /** خيارات الحالة من الخادم — لا نسخة يدوية تتخلّف عن InvoiceStatusEnum */
    statusOptions: { value: string; label: string }[];
    filterOptions: { employees: NamedOption[]; paymentMethods: NamedOption[]; services: (NamedOption & { serviceName: string })[] };
    filters: InvoiceFilters;
}

export default function InvoicesIndex({ items, isSuperAdmin, availableTypes, branches, statusOptions, filterOptions, filters }: Props) {
    // تاسك 125: مراجع الحسابات يطّلع ولا يطبع.
    const canPrint = usePage<SharedData>().props.auth.role !== 'auditor';
    // Filtering follows the report pages: the selects live in a modal, the date
    // range stays visible above the table, and applied values show as removable
    // chips. 'all' is the cleared value for the selects — useReportFilters drops
    // it from the query, so the controller keeps seeing an absent parameter.
    const defaults = useMemo<FilterValues>(
        () => ({
            search: '',
            type: 'all',
            status: 'all',
            branch_id: 'all',
            delivery: 'all',
            user_id: 'all',
            payment_method_id: 'all',
            branch_service_id: 'all',
            date_from: '',
            date_to: '',
            time_from: '',
            time_to: '',
        }),
        [],
    );

    const applied: FilterValues = {
        search: filters.search ?? '',
        type: filters.type ?? 'all',
        status: filters.status ?? 'all',
        branch_id: filters.branch_id ?? 'all',
        delivery: filters.delivery ?? 'all',
        user_id: filters.user_id ?? 'all',
        payment_method_id: filters.payment_method_id ?? 'all',
        branch_service_id: filters.branch_service_id ?? 'all',
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
        time_from: filters.time_from ?? '',
        time_to: filters.time_to ?? '',
    };
    const f = useReportFilters(INVOICES_URL, applied, defaults);

    // اختيار السوبر أدمن فرعاً يُضيّق الموظفين والخدمات وطرق الدفع على ذلك الفرع —
    // كما يراها مدير الفرع. طرق الدفع العامة (بلا فرع) تبقى لكل فرع.
    const belongsTo = (branch: string, o: NamedOption, globalOk = false) =>
        branch === 'all' || o.branchId?.toString() === branch || (globalOk && o.branchId === null);
    const branchEmployees = filterOptions.employees.filter((e) => belongsTo(f.draft.branch_id, e));
    const branchPaymentMethods = filterOptions.paymentMethods.filter((m) => belongsTo(f.draft.branch_id, m, true));
    const branchServices = filterOptions.services.filter((s) => belongsTo(f.draft.branch_id, s));
    const changeBranch = (branch: string) => {
        f.setField('branch_id', branch);
        // اختيارٌ من فرعٍ آخر كان سيُفرغ النتيجة بصمت — فيُمسح.
        (
            [
                ['user_id', filterOptions.employees, false],
                ['payment_method_id', filterOptions.paymentMethods, true],
                ['branch_service_id', filterOptions.services, false],
            ] as const
        ).forEach(([key, rows, globalOk]) => {
            const row = rows.find((r) => r.id.toString() === f.draft[key]);
            if (row && !belongsTo(branch, row, globalOk)) f.setField(key, 'all');
        });
    };

    const [returnItem, setReturnItem] = useState<InvoiceListItem | null>(null);
    const [returnReason, setReturnReason] = useState('');
    const [returning, setReturning] = useState(false);
    // «تم تسليم العمل»: ختم لا رجعة فيه، فيمرّ بتأكيد ولو كان زراً سريعاً في الصف.
    const [deliverItem, setDeliverItem] = useState<InvoiceListItem | null>(null);
    const [delivering, setDelivering] = useState(false);
    // اعتماد الفاتورة من صفّ القائمة (تاسك 88) — بتأكيدٍ صغير، فهو قرار مالي.
    const [approveItem, setApproveItem] = useState<InvoiceListItem | null>(null);
    const [approving, setApproving] = useState(false);

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

    function confirmDeliver() {
        if (!deliverItem) return;
        setDelivering(true);
        router.post(
            serviceInvoice.deliver(deliverItem.id).url,
            {},
            {
                preserveScroll: true,
                onError: (e) => toast.error((Object.values(e)[0] as string) ?? 'تعذّر تسجيل تسليم العمل.'),
                onSuccess: () => toast.success('تم تسليم العمل.'),
                onFinish: () => {
                    setDelivering(false);
                    setDeliverItem(null);
                },
            },
        );
    }

    /**
     * الاعتماد من القائمة (تاسك 88). الفاتورة الناقصةُ حارساً لا تُرسل أصلاً —
     * يُنقل المستخدم إلى شاشتها حيث يُستكمل الناقص، برسالةٍ تسمّيه. الفشل الصامت
     * هو بالضبط العطل الذي أُصلح في كوميت e74d0a9.
     */
    function startApprove(item: InvoiceListItem) {
        if (item.approveBlockedReason === 'method') {
            toast.error('حدّد طريقة الدفع أولاً — فُتحت شاشة الفاتورة.');
            router.visit(`/invoices/${item.type}/${item.id}`);
            return;
        }
        if (item.approveBlockedReason === 'receipt') {
            toast.error('أرفق إيصال التحويل أولاً — فُتحت شاشة الفاتورة.');
            router.visit(`/invoices/${item.type}/${item.id}`);
            return;
        }
        setApproveItem(item);
    }

    function confirmApprove() {
        if (!approveItem) return;
        const item = approveItem;
        setApproving(true);
        router.patch(
            serviceInvoice.pay(item.id).url,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success(`تم اعتماد الفاتورة ${item.invoiceNumber}.`),
                onError: (e) => {
                    // عجز الخامات له حوار إقرارٍ في شاشة الفاتورة — لا يُكرَّر هنا.
                    if (e.materials_shortage) {
                        toast.error('يوجد عجز في خامات المخزون — أُقرّه من شاشة الفاتورة.');
                        router.visit(`/invoices/${item.type}/${item.id}`);
                        return;
                    }
                    toast.error((Object.values(e)[0] as string) ?? 'تعذّر اعتماد الفاتورة.');
                },
                onFinish: () => {
                    setApproving(false);
                    setApproveItem(null);
                },
            },
        );
    }


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
        const label = statusOptions.find((o) => o.value === applied.status)?.label ?? applied.status;
        chips.push({ key: 'status', label: `الحالة: ${label}`, onRemove: () => f.remove('status') });
    }
    if (f.isActive('delivery')) {
        const label = DELIVERY_OPTIONS.find((o) => o.value === applied.delivery)?.label ?? applied.delivery;
        chips.push({ key: 'delivery', label: `التسليم: ${label}`, onRemove: () => f.remove('delivery') });
    }
    if (f.isActive('user_id')) {
        const name = filterOptions.employees.find((e) => e.id.toString() === applied.user_id)?.name ?? applied.user_id;
        chips.push({ key: 'user_id', label: `الموظف: ${name}`, onRemove: () => f.remove('user_id') });
    }
    if (f.isActive('branch_service_id')) {
        const name = filterOptions.services.find((s) => s.id.toString() === applied.branch_service_id)?.name ?? applied.branch_service_id;
        chips.push({ key: 'branch_service_id', label: `الخدمة: ${name}`, onRemove: () => f.remove('branch_service_id') });
    }
    // تاسك 114: شريحةٌ واحدة للساعات، تُزيل الطرفين معاً.
    if (f.isActive('time_from') || f.isActive('time_to')) {
        const label = `الساعة: ${applied.time_from || '00:00'} – ${applied.time_to || '23:59'}`;
        chips.push({ key: 'time', label, onRemove: () => f.replaceMany({ time_from: '', time_to: '' }) });
    }
    if (f.isActive('payment_method_id')) {
        const name = filterOptions.paymentMethods.find((m) => m.id.toString() === applied.payment_method_id)?.name ?? applied.payment_method_id;
        chips.push({ key: 'payment_method_id', label: `طريقة الدفع: ${name}`, onRemove: () => f.remove('payment_method_id') });
    }

    const columns = useMemo<ColumnDef<InvoiceListItem>[]>(
        () => [
            {
                key: 'invoiceNumber',
                header: 'رقم الفاتورة',
                cell: (item) => (
                    <span className="inline-flex items-center gap-1.5">
                        <Link href={`/invoices/${item.type}/${item.id}`} className="text-foreground font-medium hover:underline" dir="ltr">
                            {item.invoiceNumber}
                        </Link>
                        {/* تاسك 100: رسائل داخلية غير مقروءة على الفاتورة */}
                        {item.unreadMessages > 0 && (
                            <span
                                className="inline-flex items-center gap-0.5 rounded-full bg-sky-100 px-1.5 text-xs font-medium text-sky-700 tabular-nums dark:bg-sky-950 dark:text-sky-300"
                                title={`${item.unreadMessages} رسالة داخلية غير مقروءة`}
                            >
                                <MessageSquare className="size-3" aria-hidden />
                                {item.unreadMessages}
                            </span>
                        )}
                    </span>
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
                className: 'whitespace-nowrap',
                cell: (item) => <span>{formatDate(item.createdAt)}</span>,
            },
            {
                key: 'deliveryAt',
                header: 'موعد التسليم',
                cell: (item) =>
                    item.deliveryAt || item.deliveredAt ? (
                        <DeliveryBadge
                            deliveryAt={item.deliveryAt}
                            deliveryStatus={item.deliveryStatus}
                            deliveredAt={item.deliveredAt}
                            showLabel
                        />
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
                className: 'whitespace-nowrap',
                cell: (item) => item.employeeName ?? <span className="text-muted-foreground">—</span>,
            },
            ...(isSuperAdmin
                ? [
                      {
                          key: 'branchName',
                          header: 'الفرع',
                          className: 'whitespace-nowrap',
                          cell: (item: InvoiceListItem) => item.branchName ?? <span className="text-muted-foreground">—</span>,
                      },
                  ]
                : []),
            {
                key: 'totalAmount',
                header: 'الإجمالي',
                className: 'whitespace-nowrap',
                cell: (item) => (
                    <span className="font-semibold tabular-nums" dir="ltr">
                        {formatCurrency(item.totalAmount)}
                    </span>
                ),
            },
            {
                key: 'paymentMethodName',
                header: 'طريقة الدفع',
                className: 'whitespace-nowrap',
                cell: (item) => item.paymentMethodName ?? <span className="text-muted-foreground">—</span>,
            },
            {
                key: 'remainingAmount',
                header: 'المتبقي',
                className: 'whitespace-nowrap',
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
                className: 'whitespace-nowrap',
                cell: (item) => {
                    const badge = (
                        <Badge variant="outline" className={INVOICE_STATUS_COLORS[item.status]}>
                            {item.statusLabel}
                        </Badge>
                    );

                    // A rejected invoice carries its reason on the badge, so the
                    // employee sees why without opening the invoice.
                    const primary =
                        item.status === 'cancelled' && item.cancellationReason ? (
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
                        ) : (
                            badge
                        );

                    // المرتجع الجزئي لا يغيّر الحالة عمداً — الفاتورة تبقى قائمة
                    // ومحتسبة في المبيعات، ويُطرح صفُّ مرتجعها وحده. فبغير هذا
                    // الوسم يمرّ المرتجع صامتاً في القائمة. أما المرتجع الكامل
                    // فيقلب الحالة نفسها إلى «مرتجع» فيغني عنه.
                    if (item.refundedAmount <= 0 || item.status === 'returned') {
                        return primary;
                    }

                    return (
                        <div className="flex flex-col items-start gap-1">
                            {primary}
                            <Badge variant="outline" className="border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-300">
                                مرتجع جزئي · {formatCurrency(item.refundedAmount)}
                            </Badge>
                        </div>
                    );
                },
            },
            {
                key: 'actions',
                header: '',
                headerClassName: 'w-32',
                cell: (item) => {
                    // «عرض» والإجراء الأهم (اعتماد، وإلا تسليم) ظاهران؛ والباقي في قائمة ⋯
                    // فلا يتّسع العمود ولا يُقصّ مهما اجتمعت الصلاحيات.
                    const quickDeliver = item.canDeliver && !item.canApprove;
                    const hasMenu = canPrint || (item.canDeliver && !quickDeliver) || item.canEdit || item.canReturn || item.returnLocked;

                    return (
                        <div className="flex items-center gap-1.5">
                            <Button variant="outline" size="sm" className={ACTION_BUTTON} asChild>
                                <Link href={`/invoices/${item.type}/${item.id}`} aria-label="عرض" title="عرض">
                                    <Eye className="h-3.5 w-3.5" />
                                </Link>
                            </Button>
                            {/* اعتماد الفاتورة غير المسددة من القائمة (تاسك 88). */}
                            {item.canApprove && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className={cn(ACTION_BUTTON, 'text-green-700 hover:text-green-800 dark:text-green-400')}
                                    aria-label="اعتماد الفاتورة"
                                    title={
                                        item.approveBlockedReason === 'method'
                                            ? 'ينقصها تحديد طريقة الدفع'
                                            : item.approveBlockedReason === 'receipt'
                                              ? 'ينقصها إيصال التحويل'
                                              : 'اعتماد الفاتورة'
                                    }
                                    onClick={() => startApprove(item)}
                                >
                                    <CheckCircle2 className="h-3.5 w-3.5" />
                                </Button>
                            )}
                            {/* زر سريع لفواتير الخدمة الحيّة التي لم يُسلَّم عملها بعد (تاسك 31). */}
                            {quickDeliver && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className={cn(ACTION_BUTTON, 'text-green-700 hover:text-green-800 dark:text-green-400')}
                                    aria-label="تم تسليم العمل"
                                    title="تم تسليم العمل"
                                    onClick={() => setDeliverItem(item)}
                                >
                                    <PackageCheck className="h-3.5 w-3.5" />
                                </Button>
                            )}
                            {hasMenu && (
                                <DropdownMenu dir="rtl">
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="outline" size="sm" className={ACTION_BUTTON} aria-label="إجراءات أخرى" title="إجراءات أخرى">
                                            <MoreHorizontal className="h-3.5 w-3.5" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" className="min-w-44">
                                        {canPrint && (
                                            <DropdownMenuItem asChild>
                                                <a href={`/invoices/${item.type}/${item.id}/print?format=a4`} target="_blank" rel="noreferrer">
                                                    <Printer /> طباعة
                                                </a>
                                            </DropdownMenuItem>
                                        )}
                                        {item.canDeliver && !quickDeliver && (
                                            <DropdownMenuItem onSelect={() => setDeliverItem(item)}>
                                                <PackageCheck /> تم تسليم العمل
                                            </DropdownMenuItem>
                                        )}
                                        {item.canEdit && (
                                            <DropdownMenuItem asChild>
                                                <Link href={(item.type === 'product' ? posProduct : posService).edit(item.id).url}>
                                                    <Pencil /> تعديل
                                                </Link>
                                            </DropdownMenuItem>
                                        )}
                                        {(item.canReturn || item.returnLocked) && <DropdownMenuSeparator />}
                                        {item.canReturn && (
                                            <DropdownMenuItem className="text-destructive focus:text-destructive" onSelect={() => setReturnItem(item)}>
                                                <Undo2 /> استرجاع الفاتورة
                                            </DropdownMenuItem>
                                        )}
                                        {item.returnLocked && (
                                            <DropdownMenuItem disabled>
                                                <Undo2 /> مُرتجعة بالفعل
                                            </DropdownMenuItem>
                                        )}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                        </div>
                    );
                },
            },
        ],
        [isSuperAdmin, canPrint],
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
                        onReset={f.reset}
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
                                onChange={changeBranch}
                                allLabel="كل الفروع"
                                options={branches.map((b) => ({ value: b.id.toString(), label: b.name }))}
                            />
                        )}
                        <FilterSelect
                            label="الحالة"
                            value={f.draft.status}
                            onChange={(v) => f.setField('status', v)}
                            allLabel="كل الحالات"
                            options={statusOptions}
                        />
                        <FilterSelect
                            label="الموظف"
                            value={f.draft.user_id}
                            onChange={(v) => f.setField('user_id', v)}
                            allLabel="كل الموظفين"
                            searchable
                            options={branchEmployees.map((e) => ({ value: e.id.toString(), label: e.name }))}
                        />
                        {/* الخدمة تخصّ فواتير الخدمات، فاختيارها يُقصي فواتير المنتجات. */}
                        <FilterSelect
                            label="نوع الخدمة"
                            value={f.draft.branch_service_id}
                            onChange={(v) => f.setField('branch_service_id', v)}
                            allLabel="كل الخدمات"
                            searchable
                            options={branchServices.map((s) => ({
                                value: s.id.toString(),
                                label: f.draft.branch_id === 'all' ? s.name : s.serviceName,
                            }))}
                        />
                        <FilterSelect
                            label="طريقة الدفع"
                            value={f.draft.payment_method_id}
                            onChange={(v) => f.setField('payment_method_id', v)}
                            allLabel="كل الطرق"
                            options={branchPaymentMethods.map((m) => ({ value: m.id.toString(), label: m.name }))}
                        />
                        <FilterSelect
                            label="موعد التسليم"
                            value={f.draft.delivery}
                            onChange={(v) => f.setField('delivery', v)}
                            allLabel="كل المواعيد"
                            options={DELIVERY_OPTIONS}
                        />
                        {/* تاسك 114: الساعات نفسها من كل يوم في المدى، بوقت إنشاء الفاتورة. */}
                        <FilterField label="من الساعة" htmlFor="filter-time-from">
                            <Input id="filter-time-from" type="time" value={f.draft.time_from} onChange={(e) => f.setField('time_from', e.target.value)} />
                        </FilterField>
                        <FilterField label="إلى الساعة" htmlFor="filter-time-to">
                            <Input id="filter-time-to" type="time" value={f.draft.time_to} onChange={(e) => f.setField('time_to', e.target.value)} />
                        </FilterField>
                    </FilterModal>
                </div>

                <Card className="mb-6 flex flex-wrap items-end justify-between gap-x-6 gap-y-4 border px-4 py-3.5">
                    <FilterSearch filters={f} value={applied.search} placeholder="رقم الفاتورة أو اسم الموظف..." />
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
                    from={items.meta.from as number}
                    to={items.meta.to as number}
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

            <Dialog open={!!deliverItem} onOpenChange={(open) => !open && !delivering && setDeliverItem(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تم تسليم العمل</DialogTitle>
                        <DialogDescription>
                            تُختم الفاتورة {deliverItem?.invoiceNumber} بأن عملها سُلّم للعميل الآن، فتصير حالة موعد التسليم «تم تسليم العمل». لا
                            يتغيّر شيء في مبلغ الفاتورة ولا في حالتها المالية.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeliverItem(null)} disabled={delivering}>
                            تراجع
                        </Button>
                        <Button className="bg-green-600 text-white hover:bg-green-700" onClick={confirmDeliver} disabled={delivering}>
                            {delivering ? <Loader2 className="size-4 animate-spin" /> : <PackageCheck className="size-4" />} تأكيد التسليم
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={!!approveItem} onOpenChange={(open) => !open && !approving && setApproveItem(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>اعتماد الفاتورة</DialogTitle>
                        <DialogDescription>
                            تُعتمد الفاتورة {approveItem?.invoiceNumber} بكامل قيمتها {approveItem ? formatCurrency(approveItem.totalAmount) : ''}
                            {approveItem?.customerName ? ` للعميل ${approveItem.customerName}` : ''} بطريقة الدفع «{approveItem?.paymentMethodName}»، فتصير
                            حالتها «مدفوعة» وتُقيَّد عمولاتها ونقاط ولائها وتُخصم خاماتها من المخزون.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setApproveItem(null)} disabled={approving}>
                            تراجع
                        </Button>
                        <Button className="bg-green-600 text-white hover:bg-green-700" onClick={confirmApprove} disabled={approving}>
                            {approving ? <Loader2 className="size-4 animate-spin" /> : <CheckCircle2 className="size-4" />} تأكيد الاعتماد
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
