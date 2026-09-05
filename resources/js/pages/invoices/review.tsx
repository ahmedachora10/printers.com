import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import InvoiceCustomerFields, { type InvoiceCustomerErrors, type InvoiceCustomerFormData } from '@/components/invoices/invoice-customer-fields';
import MaterialsShortageDialog from '@/components/invoices/materials-shortage-dialog';
import RecordPaymentModal from '@/components/invoices/record-payment-modal';
import DateRangeBar from '@/components/reports/date-range-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Toaster } from '@/components/ui/sonner';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { formatLineSize, formatLineUnitPrice, type LineUnitPriceBasis } from '@/lib/invoice';
import { formatCurrency, formatQty } from '@/lib/utils';
import posService from '@/routes/pos/service';
import serviceInvoice from '@/routes/invoices/service';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { ArrowDownWideNarrow, ArrowUpNarrowWide, CheckCircle2, ChevronDown, ClipboardList, Paperclip, Pencil, Search, User, UserPlus, Wallet, X, XCircle } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

interface ReviewLine {
    name: string;
    notes: string | null;
    qty: number;
    unitPrice: number;
    /** ما يقيسه السعر: 'sqm' سعر متر مربع، و'linear' سعر متر طولي، و null سعر وحدة */
    unitPriceBasis?: LineUnitPriceBasis;
    widthCm: number | null;
    heightCm: number | null;
    discountPct: number;
    subtotal: number;
}

interface ReviewInvoice {
    id: number;
    invoiceNumber: string;
    createdAt: string | null;
    employeeName: string | null;
    customerId: number | null;
    customerName: string | null;
    customerPhone: string | null;
    customerTaxNumber: string | null;
    branchName: string | null;
    paymentMethod: string | null;
    paymentMethodId: number | null;
    paymentMethodOptions: { id: number; name: string; requiresAttachment?: boolean }[];
    receiptUrl: string | null;
    subtotal: number;
    vatAmount: number;
    totalAmount: number;
    /** سقف الدفعة الأولى — يساوي الإجمالي، فالطابور لا يضم إلا ما لم يُقبض منه شيء. */
    remainingAmount: number;
    /** فتح شاشة التعديل الكاملة — لمدير الفرع لا للمحاسب */
    canEdit: boolean;
    lines: ReviewLine[];
}

interface Props {
    /** الصفحة الحالية من الطابور فقط — عددها الكلّي في `meta.total`. */
    invoices: ReviewInvoice[];
    meta: { currentPage: number; lastPage: number; perPage: number; total: number; from: number | null; to: number | null };
    /** إجماليات **المدى المطبَّق** لا الصفحة المعروضة. */
    summary: { quotesCount: number; quotesTotal: number };
    filters: {
        from?: string | null;
        to?: string | null;
        search?: string | null;
        sort: string;
        dir: 'asc' | 'desc';
    };
    isSuperAdmin: boolean;
}

const REVIEW_URL = '/invoices/service/review';

const SORT_OPTIONS = [
    { value: 'created_at', label: 'تاريخ التحرير' },
    { value: 'total_amount', label: 'قيمة العرض' },
];

const reviewLineColumns: ColumnDef<ReviewLine>[] = [
    {
        key: 'name',
        header: 'الخدمة',
        cell: (line) => (
            <>
                {line.name}
                {line.notes && <span className="text-muted-foreground block text-xs whitespace-pre-line">{line.notes}</span>}
                {formatLineSize(line) && <span className="text-muted-foreground block text-xs">المقاس: {formatLineSize(line)}</span>}
            </>
        ),
    },
    { key: 'qty', header: 'الكمية', headerClassName: 'text-center', className: 'text-center', cell: (line) => formatQty(line.qty) },
    { key: 'unitPrice', header: 'السعر', headerClassName: 'text-center', className: 'text-center', cell: (line) => formatLineUnitPrice(line) },
    { key: 'subtotal', header: 'الإجمالي', headerClassName: 'text-start', className: 'text-start', cell: (line) => formatCurrency(line.subtotal) },
];

const breadcrumbs: BreadcrumbItem[] = [{ title: 'عروض الاسعار', href: serviceInvoice.review().url }];

const formatDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('en-GB') : '—');

export default function InvoiceReview({ invoices, meta, summary, filters, isSuperAdmin }: Props) {
    const { props } = usePage<SharedData>();

    const applied: FilterValues = {
        from: filters.from ?? '',
        to: filters.to ?? '',
        search: filters.search ?? '',
        sort: filters.sort,
        dir: filters.dir,
    };

    // الفرز الافتراضي (created_at تنازلياً) لا يُكتب في الرابط — وإلا حمل كل
    // تنقّل معاملين لا يغيّران شيئاً.
    const f = useReportFilters(REVIEW_URL, applied, { from: '', to: '', search: '', sort: 'created_at', dir: 'desc' });

    const [search, setSearch] = useState(applied.search);
    const searchTimeout = useRef<ReturnType<typeof setTimeout> | null>(null);

    // زرّ الرجوع يعيد الرابط بلا بحث، فيجب أن يتبعه الحقل.
    useEffect(() => setSearch(filters.search ?? ''), [filters.search]);

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => f.replace('search', value), 400);
    };

    const hasFilters = !!applied.from || !!applied.to || !!applied.search;
    const [paying, setPaying] = useState<ReviewInvoice | null>(null);
    // نص عجز الخامات كما ردّه الخادم، والفاتورة التي وقف عندها.
    const [materialsShortage, setMaterialsShortage] = useState<{ invoice: ReviewInvoice; message: string } | null>(null);
    const [payingPartial, setPayingPartial] = useState<ReviewInvoice | null>(null);
    const [cancelling, setCancelling] = useState<ReviewInvoice | null>(null);
    const [reason, setReason] = useState('');
    const [reasonError, setReasonError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [uploadingId, setUploadingId] = useState<number | null>(null);
    const [savingPaymentId, setSavingPaymentId] = useState<number | null>(null);
    // طريقة دفعٍ اختِيرت وتنتظر إيصالها قبل أن تُرسَل.
    const [pendingMethod, setPendingMethod] = useState<{ invoiceId: number; methodId: number } | null>(null);
    const [expanded, setExpanded] = useState<Record<number, boolean>>({});
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editData, setEditData] = useState<InvoiceCustomerFormData>({ full_name: '', phone: '', tax_number: '' });
    const [editErrors, setEditErrors] = useState<InvoiceCustomerErrors>({});
    const [savingCustomer, setSavingCustomer] = useState(false);

    useEffect(() => {
        if (props.success) {
            toast.success(props.success as string);
        }
    }, [props.success]);

    function toggleExpanded(id: number) {
        setExpanded((prev) => ({ ...prev, [id]: !prev[id] }));
    }

    function startEditing(invoice: ReviewInvoice) {
        setEditingId(invoice.id);
        setEditData({
            full_name: invoice.customerName ?? '',
            phone: invoice.customerPhone ?? '',
            tax_number: invoice.customerTaxNumber ?? '',
        });
        setEditErrors({});
    }

    function cancelEditing() {
        setEditingId(null);
        setEditErrors({});
    }

    function saveCustomer(invoice: ReviewInvoice) {
        setSavingCustomer(true);
        router.patch(
            serviceInvoice.updateCustomer(invoice.id).url,
            { full_name: editData.full_name.trim(), phone: editData.phone.trim(), tax_number: editData.tax_number.trim() },
            {
                preserveScroll: true,
                onError: (e) => setEditErrors({ full_name: e.full_name, phone: e.phone, tax_number: e.tax_number }),
                onSuccess: () => {
                    setEditingId(null);
                    setEditErrors({});
                },
                onFinish: () => setSavingCustomer(false),
            },
        );
    }

    function changePaymentMethod(invoice: ReviewInvoice, value: string) {
        const methodId = Number(value);
        if (methodId === invoice.paymentMethodId) return;

        // طريقةٌ تشترط إيصالاً ولمّا يُرفع: يُطلب الملف أولاً ثم يُرسل مع الطريقة
        // في طلبٍ واحد — الخادم لا يقبل إحداهما دون الأخرى. وإن صرف المستخدم
        // نافذة الملفات بقيت الطريقة على حالها.
        const needsReceipt = invoice.paymentMethodOptions.find((m) => m.id === methodId)?.requiresAttachment === true && !invoice.receiptUrl;

        if (needsReceipt) {
            setPendingMethod({ invoiceId: invoice.id, methodId });
            document.getElementById(`receipt-${invoice.id}`)?.click();

            return;
        }

        savePaymentMethod(invoice.id, methodId);
    }

    function savePaymentMethod(invoiceId: number, methodId: number, receipt?: File) {
        setSavingPaymentId(invoiceId);
        // POST مع `_method` لأن رفع ملف عبر PATCH لا يمرّ في multipart.
        router.post(
            serviceInvoice.updatePaymentMethod(invoiceId).url,
            { _method: 'patch', payment_method_id: methodId, ...(receipt ? { receipt } : {}) },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (e) => toast.error(e.payment_method_id ?? e.receipt ?? 'تعذّر تحديث طريقة الدفع.'),
                onFinish: () => {
                    setSavingPaymentId(null);
                    setPendingMethod(null);
                },
            },
        );
    }

    function confirmPay(invoice: ReviewInvoice | null = paying, confirmedShortage = false) {
        if (!invoice) return;
        setSubmitting(true);
        router.patch(
            serviceInvoice.pay(invoice.id).url,
            confirmedShortage ? { confirm_materials_shortage: true } : {},
            {
                preserveScroll: true,
                // العجز يرجع مرة واحدة على هذا المفتاح؛ الحوار يعيد الإرسال مُقِرّاً.
                onError: (errors) =>
                    setMaterialsShortage(errors.materials_shortage ? { invoice, message: errors.materials_shortage } : null),
                onSuccess: () => setMaterialsShortage(null),
                onFinish: () => {
                    setSubmitting(false);
                    setPaying(null);
                },
            },
        );
    }

    function uploadReceipt(invoiceId: number, file: File | undefined) {
        if (!file) {
            setPendingMethod(null);

            return;
        }

        // نفس الحقل يخدم الحالتين: إيصالٌ يُرفع وحده، أو إيصالٌ طُلب لأجل طريقة
        // دفعٍ تنتظره.
        if (pendingMethod?.invoiceId === invoiceId) {
            savePaymentMethod(invoiceId, pendingMethod.methodId, file);

            return;
        }

        setUploadingId(invoiceId);
        router.post(
            serviceInvoice.receipt(invoiceId).url,
            { receipt: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (e) => toast.error(e.receipt ?? 'تعذّر رفع الإيصال.'),
                onFinish: () => setUploadingId(null),
            },
        );
    }

    function confirmCancel() {
        if (!cancelling) return;
        if (reason.trim().length < 3) {
            setReasonError('يجب ذكر سبب الإلغاء.');
            return;
        }
        setSubmitting(true);
        router.patch(
            serviceInvoice.cancel(cancelling.id).url,
            { reason: reason.trim() },
            {
                preserveScroll: true,
                onError: (e) => setReasonError(e.reason ?? 'تعذّر إلغاء الفاتورة.'),
                onSuccess: () => {
                    setCancelling(null);
                    setReason('');
                    setReasonError(null);
                },
                onFinish: () => setSubmitting(false),
            },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="عروض الاسعار" />
            <Toaster position="top-center" richColors />

            <div className="space-y-4 p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <ClipboardList className="size-5" />
                    <h1 className="text-lg font-semibold">عروض الاسعار بانتظار الاعتماد</h1>
                    {/* الشارة تتبع المدى المطبَّق لا كل الطابور. */}
                    <Badge variant="secondary">{summary.quotesCount}</Badge>
                    <span className="text-muted-foreground text-sm">بإجمالي {formatCurrency(summary.quotesTotal)}</span>
                </div>

                <Card className="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 rounded-md px-4 py-3.5 sm:px-5">
                    <DateRangeBar filters={f} from={applied.from} to={applied.to} extended />
                    <div className="flex flex-1 flex-wrap items-center justify-end gap-2">
                        <div className="relative min-w-0 flex-1 sm:max-w-64">
                            <Search className="text-muted-foreground pointer-events-none absolute top-1/2 start-2.5 size-4 -translate-y-1/2" />
                            <Input
                                value={search}
                                onChange={(e) => handleSearchChange(e.target.value)}
                                placeholder="بحث برقم الفاتورة أو العميل أو الموظف..."
                                className="h-9 ps-8 sm:h-8"
                            />
                        </div>
                        <Select value={applied.sort} onValueChange={(v) => f.replace('sort', v)}>
                            <SelectTrigger className="h-9 w-40 sm:h-8">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {SORT_OPTIONS.map((o) => (
                                    <SelectItem key={o.value} value={o.value}>
                                        {o.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="h-9 sm:h-8"
                            onClick={() => f.replace('dir', applied.dir === 'desc' ? 'asc' : 'desc')}
                            title={applied.dir === 'desc' ? 'تنازلي' : 'تصاعدي'}
                        >
                            {applied.dir === 'desc' ? <ArrowDownWideNarrow className="size-4" /> : <ArrowUpNarrowWide className="size-4" />}
                        </Button>
                        {/* حقل type="date" لا يُفرَّغ بالكتابة، فالمسح يحتاج زرّاً. */}
                        {hasFilters && (
                            <Button type="button" variant="ghost" size="sm" onClick={f.reset}>
                                <X className="size-3" /> كل الفترات
                            </Button>
                        )}
                    </div>
                </Card>

                {invoices.length === 0 ? (
                    <Card>
                        <CardContent className="text-muted-foreground py-12 text-center text-sm">
                            {hasFilters ? 'لا توجد عروض أسعار مطابقة للتصفية.' : 'لا توجد فواتير آجلة بحاجة للمراجعة.'}
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {invoices.map((invoice) => {
                            const isOpen = !!expanded[invoice.id];
                            const isEditing = editingId === invoice.id;
                            // تاسك 59: لا اعتماد بلا طريقة دفع — والخادم يرفضه
                            // أيضاً، فالتعطيل هنا توضيحٌ لا حارس. ومثله طريقةٌ
                            // تشترط إيصالاً لم يُرفع بعد: الإرفاق من نفس البطاقة.
                            const needsReceipt =
                                invoice.paymentMethodOptions.find((m) => m.id === invoice.paymentMethodId)?.requiresAttachment === true &&
                                !invoice.receiptUrl;
                            const canApprove = invoice.paymentMethodId !== null && !needsReceipt;
                            const approveHint =
                                invoice.paymentMethodId === null
                                    ? 'حدّد طريقة الدفع أولاً'
                                    : needsReceipt
                                      ? 'أرفق إيصال التحويل أولاً'
                                      : 'اعتماد الدفع';

                            return (
                                <Card key={invoice.id}>
                                    <CardHeader className="cursor-pointer pb-3" onClick={() => toggleExpanded(invoice.id)}>
                                        <div className="flex flex-wrap items-start justify-between gap-2">
                                            {/* Collapsed: who raised it and for how much. Everything
                                                else waits behind «عرض». */}
                                            <div className="space-y-1">
                                                <CardTitle className="text-base">{invoice.invoiceNumber}</CardTitle>
                                                <p className="text-muted-foreground flex items-center gap-1.5 text-xs">
                                                    <User className="size-3.5 shrink-0" />
                                                    <span>{invoice.employeeName ?? '—'}</span>
                                                </p>
                                            </div>
                                            <div className="flex shrink-0 items-center gap-2">
                                                <span className="text-base font-bold">{formatCurrency(invoice.totalAmount)}</span>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="text-sky-600 hover:text-sky-700 dark:text-sky-400 dark:hover:text-sky-300"
                                                    aria-label="تسجيل عربون"
                                                    title="تسجيل عربون"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        setPayingPartial(invoice);
                                                    }}
                                                >
                                                    <Wallet className="size-4" />
                                                </Button>
                                                {/* تاسك 70: تصحيح الفاتورة — وتكلفة الخامات خاصّةً —
                                                    قبل الاعتماد؛ فبعده يُقفل التحرير. والمحاسب لا يراه:
                                                    الخدمات والأسعار ليست له، وبيانات العميل وطريقة الدفع
                                                    في متناوله هنا على أي حال. */}
                                                {invoice.canEdit && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        aria-label="تعديل الفاتورة"
                                                        title="تعديل الفاتورة قبل الاعتماد"
                                                        onClick={(e) => {
                                                            e.stopPropagation();
                                                            router.get(posService.edit(invoice.id).url);
                                                        }}
                                                    >
                                                        <Pencil className="size-4" />
                                                    </Button>
                                                )}
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    className="text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300"
                                                    aria-label={approveHint}
                                                    title={approveHint}
                                                    disabled={!canApprove}
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        setPaying(invoice);
                                                    }}
                                                >
                                                    <CheckCircle2 className="size-4" />
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-expanded={isOpen}
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        toggleExpanded(invoice.id);
                                                    }}
                                                >
                                                    <ChevronDown className={`size-4 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
                                                    {isOpen ? 'إخفاء' : 'عرض'}
                                                </Button>
                                            </div>
                                        </div>
                                    </CardHeader>
                                    {isOpen && (
                                        <CardContent className="space-y-3">
                                            <div className="bg-muted/40 space-y-2 rounded-md border p-3 text-sm">
                                                <div className="flex items-center justify-between">
                                                    <span className="text-muted-foreground flex items-center gap-1.5">
                                                        <User className="size-4" /> بيانات العميل
                                                    </span>
                                                    {!isEditing &&
                                                        (invoice.customerId ? (
                                                            <Button type="button" variant="ghost" size="sm" onClick={() => startEditing(invoice)}>
                                                                <Pencil className="size-3.5" /> تعديل
                                                            </Button>
                                                        ) : (
                                                            <Button type="button" variant="ghost" size="sm" onClick={() => startEditing(invoice)}>
                                                                <UserPlus className="size-3.5" /> إضافة عميل
                                                            </Button>
                                                        ))}
                                                </div>

                                                {isEditing ? (
                                                    <div className="space-y-2">
                                                        <InvoiceCustomerFields
                                                            idPrefix={`review-${invoice.id}`}
                                                            data={editData}
                                                            onChange={(field, value) => setEditData((prev) => ({ ...prev, [field]: value }))}
                                                            errors={editErrors}
                                                            disabled={savingCustomer}
                                                            autoFocus
                                                        />
                                                        <div className="flex gap-2 pt-1">
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                onClick={() => saveCustomer(invoice)}
                                                                disabled={savingCustomer}
                                                            >
                                                                <CheckCircle2 className="size-4" /> حفظ
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={cancelEditing}
                                                                disabled={savingCustomer}
                                                            >
                                                                إلغاء
                                                            </Button>
                                                        </div>
                                                    </div>
                                                ) : (
                                                    <div className="text-muted-foreground space-y-0.5 text-xs">
                                                        <p>الاسم: {invoice.customerName ?? 'عميل عابر'}</p>
                                                        <p>الجوال: {invoice.customerPhone ?? '—'}</p>
                                                        <p>الرقم الضريبي: {invoice.customerTaxNumber ?? '—'}</p>
                                                        {!invoice.customerId && (
                                                            <p className="text-amber-600 dark:text-amber-400">
                                                                عميل عابر غير مسجَّل — أضف الاسم ورقم الجوال لتسجيله.
                                                            </p>
                                                        )}
                                                    </div>
                                                )}
                                            </div>

                                            <div className="text-muted-foreground space-y-0.5 text-xs">
                                                {isSuperAdmin && invoice.branchName && <p>الفرع: {invoice.branchName}</p>}
                                                <p>التاريخ: {formatDate(invoice.createdAt)}</p>
                                            </div>

                                            <DataTable
                                                className="rounded-none border-0 bg-transparent shadow-none"
                                                columns={reviewLineColumns}
                                                data={invoice.lines}
                                                keyExtractor={(line) => invoice.lines.indexOf(line)}
                                            />

                                            <Separator />

                                            <div className="space-y-1 text-sm">
                                                <div className="text-muted-foreground flex justify-between">
                                                    <span>المجموع الفرعي</span>
                                                    <span>{formatCurrency(invoice.subtotal)}</span>
                                                </div>
                                                <div className="text-muted-foreground flex justify-between">
                                                    <span>الضريبة</span>
                                                    <span>{formatCurrency(invoice.vatAmount)}</span>
                                                </div>
                                                <div className="flex justify-between font-bold">
                                                    <span>الإجمالي</span>
                                                    <span>{formatCurrency(invoice.totalAmount)}</span>
                                                </div>
                                            </div>

                                            <div className="bg-muted/40 space-y-2 rounded-md border p-3 text-sm">
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="text-muted-foreground">طريقة الدفع</span>
                                                    {invoice.paymentMethodOptions.length > 0 ? (
                                                        <Select
                                                            value={invoice.paymentMethodId ? String(invoice.paymentMethodId) : undefined}
                                                            onValueChange={(v) => changePaymentMethod(invoice, v)}
                                                            disabled={savingPaymentId === invoice.id}
                                                        >
                                                            <SelectTrigger className="h-8 w-44">
                                                                <SelectValue placeholder="اختر طريقة الدفع" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {invoice.paymentMethodOptions.map((m) => (
                                                                    <SelectItem key={m.id} value={String(m.id)}>
                                                                        {m.name}
                                                                    </SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                    ) : (
                                                        <span className="font-medium">{invoice.paymentMethod ?? '—'}</span>
                                                    )}
                                                </div>
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="text-muted-foreground flex items-center gap-1.5">
                                                        <Paperclip className="size-4" /> إيصال التحويل
                                                    </span>
                                                    {invoice.receiptUrl ? (
                                                        <a
                                                            href={invoice.receiptUrl}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            className="text-primary font-medium hover:underline"
                                                        >
                                                            عرض الإيصال
                                                        </a>
                                                    ) : (
                                                        <span className="text-amber-600 dark:text-amber-400">لا يوجد</span>
                                                    )}
                                                </div>
                                                <Label
                                                    htmlFor={`receipt-${invoice.id}`}
                                                    className="text-primary block cursor-pointer text-xs hover:underline"
                                                >
                                                    {uploadingId === invoice.id
                                                        ? 'جارٍ الرفع...'
                                                        : invoice.receiptUrl
                                                          ? 'استبدال الإيصال'
                                                          : 'إرفاق إيصال'}
                                                </Label>
                                                <input
                                                    id={`receipt-${invoice.id}`}
                                                    type="file"
                                                    accept="image/jpeg,image/png,image/webp,application/pdf"
                                                    className="hidden"
                                                    disabled={uploadingId === invoice.id}
                                                    onChange={(e) => uploadReceipt(invoice.id, e.target.files?.[0])}
                                                />
                                            </div>

                                            {invoice.paymentMethodId === null && (
                                                <p className="text-amber-600 text-xs dark:text-amber-400">
                                                    حدّد طريقة الدفع أعلاه قبل اعتماد الفاتورة — التقرير لا ينسب مبلغاً بلا طريقة.
                                                </p>
                                            )}
                                            {needsReceipt && (
                                                <p className="text-amber-600 text-xs dark:text-amber-400">
                                                    طريقة الدفع المحددة تستلزم إيصال التحويل — أرفقه أعلاه قبل اعتماد الفاتورة.
                                                </p>
                                            )}

                                            <div className="grid grid-cols-2 gap-2 pt-1">
                                                <Button type="button" disabled={!canApprove} title={approveHint} onClick={() => setPaying(invoice)}>
                                                    <CheckCircle2 className="size-4" /> اعتماد الدفع
                                                </Button>
                                                {/* أول دفعة تُخرج الفاتورة من طابور عروض الأسعار —
                                                    ويُستكمل سدادها بعدها من صفحة الفاتورة. */}
                                                <Button type="button" variant="outline" onClick={() => setPayingPartial(invoice)}>
                                                    <Wallet className="size-4" /> تسجيل عربون
                                                </Button>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    className="text-destructive hover:text-destructive"
                                                    onClick={() => {
                                                        setReason('');
                                                        setReasonError(null);
                                                        setCancelling(invoice);
                                                    }}
                                                >
                                                    <XCircle className="size-4" /> إلغاء الفاتورة
                                                </Button>
                                            </div>
                                        </CardContent>
                                    )}
                                </Card>
                            );
                        })}
                    </div>
                )}

                <TablePagination
                    currentPage={meta.currentPage}
                    totalPages={meta.lastPage}
                    totalItems={meta.total}
                    from={meta.from}
                    to={meta.to}
                    // router.reload يحتفظ بمعاملات الرابط الحالية، فالمدى والفرز
                    // والبحث لا تضيع عند تغيير الصفحة.
                    onPageChange={(page) => router.reload({ data: { page } })}
                />
            </div>

            {/* تسجيل دفعة (عربون أو دفعة لاحقة) */}
            {payingPartial && (
                <RecordPaymentModal
                    open={!!payingPartial}
                    onOpenChange={(open) => !open && setPayingPartial(null)}
                    invoiceType="service"
                    invoiceId={payingPartial.id}
                    invoiceNumber={payingPartial.invoiceNumber}
                    remaining={payingPartial.remainingAmount}
                    paymentMethods={payingPartial.paymentMethodOptions}
                />
            )}

            {/* Mark-paid confirmation */}
            <Dialog open={!!paying} onOpenChange={(open) => !open && setPaying(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>اعتماد دفع الفاتورة</DialogTitle>
                        <DialogDescription>
                            سيتم تحويل الفاتورة {paying?.invoiceNumber} إلى مدفوعة بمبلغ {paying ? formatCurrency(paying.totalAmount) : ''} واحتساب
                            نقاط الولاء إن وُجدت.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setPaying(null)} disabled={submitting}>
                            تراجع
                        </Button>
                        <Button onClick={() => confirmPay()} disabled={submitting}>
                            <CheckCircle2 className="size-4" /> تأكيد الدفع
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <MaterialsShortageDialog
                message={materialsShortage?.message ?? null}
                processing={submitting}
                onCancel={() => setMaterialsShortage(null)}
                onConfirm={() => materialsShortage && confirmPay(materialsShortage.invoice, true)}
            />

            {/* Cancel with reason */}
            <Dialog open={!!cancelling} onOpenChange={(open) => !open && setCancelling(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>إلغاء الفاتورة</DialogTitle>
                        <DialogDescription>
                            سيتم إلغاء الفاتورة {cancelling?.invoiceNumber} وعكس عمولة الموظف غير المدفوعة واسترجاع أي نقاط مستبدلة.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="cancel-reason">سبب الإلغاء</Label>
                        <textarea
                            id="cancel-reason"
                            value={reason}
                            onChange={(e) => {
                                setReason(e.target.value);
                                setReasonError(null);
                            }}
                            rows={3}
                            className="border-input placeholder:text-muted-foreground focus-visible:ring-ring w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-sm focus-visible:ring-1 focus-visible:outline-none"
                            placeholder="اكتب سبب الإلغاء..."
                        />
                        {reasonError && <p className="text-destructive text-xs">{reasonError}</p>}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCancelling(null)} disabled={submitting}>
                            تراجع
                        </Button>
                        <Button variant="destructive" onClick={confirmCancel} disabled={submitting}>
                            <XCircle className="size-4" /> تأكيد الإلغاء
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
