import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate, formatNumber } from '@/lib/utils';
import customersRoute from '@/routes/customers';
import { type BreadcrumbItem, type Paginated } from '@/types';
import {
    type Customer,
    type CustomerFinancialSummary,
    type CustomerTier,
    type InvoiceHistoryItem,
    type LoyaltyTransaction,
} from '@/types/customer';
import { Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    ArrowLeftRight,
    Building2,
    CreditCard,
    MessageCircle,
    Phone,
    Pencil,
    Star,
    TrendingUp,
    TriangleAlert,
    User,
} from 'lucide-react';
import { useState } from 'react';

const TIER_COLORS: Record<string, string> = {
    none: 'border-border bg-muted/60 text-muted-foreground',
    bronze: 'border-amber-200 bg-amber-50 text-amber-700',
    silver: 'border-slate-200 bg-slate-100 text-slate-700',
    gold: 'border-yellow-200 bg-yellow-50 text-yellow-700',
};

const TIER_OPTIONS: { value: CustomerTier; label: string }[] = [
    { value: 'none', label: 'بدون' },
    { value: 'bronze', label: 'برونزي' },
    { value: 'silver', label: 'فضي' },
    { value: 'gold', label: 'ذهبي' },
];


const STATUS_COLORS: Record<string, string> = {
    paid: 'border-green-200 bg-green-50 text-green-700',
    partially_paid: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300',
    due: 'border-red-200 bg-red-50 text-red-700',
    cancelled: 'border-border bg-muted/60 text-muted-foreground',
    returned: 'border-red-300 bg-red-100 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-300',
};

const STATUS_LABELS: Record<string, string> = {
    paid: 'مدفوع',
    partially_paid: 'مدفوع جزئياً',
    due: 'مستحق',
    cancelled: 'ملغي',
    returned: 'مرتجع',
};

interface Props {
    customer: Customer;
    financialSummary: CustomerFinancialSummary;
    loyaltyHistory: Paginated<LoyaltyTransaction>;
    invoiceHistory: Paginated<InvoiceHistoryItem>;
    customers: Customer[];
    canOverrideTier: boolean;
}

const invoiceHistoryColumns: ColumnDef<InvoiceHistoryItem>[] = [
    { key: 'invoice_number', header: 'رقم الفاتورة', className: 'font-mono text-xs', cell: (inv) => inv.invoice_number },
    {
        key: 'type',
        header: 'النوع',
        cell: (inv) => (
            <Badge variant="secondary" className="text-xs">
                {inv.type === 'service' ? 'خدمة' : 'منتج'}
            </Badge>
        ),
    },
    {
        key: 'total_amount',
        header: 'المبلغ',
        className: 'tabular-nums',
        cell: (inv) => (
            <span dir="ltr">{formatCurrency(Number(inv.total_amount))}</span>
        ),
    },
    {
        key: 'status',
        header: 'الحالة',
        cell: (inv) => (
            <Badge variant="outline" className={STATUS_COLORS[inv.status]}>
                {STATUS_LABELS[inv.status]}
            </Badge>
        ),
    },
    { key: 'created_at', header: 'التاريخ', className: 'text-muted-foreground', cell: (inv) => formatDate(inv.created_at) },
];

export default function CustomerShow({
    customer,
    financialSummary,
    loyaltyHistory,
    invoiceHistory,
    customers,
    canOverrideTier,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'العملاء', href: customersRoute.index().url },
        { title: customer.fullName, href: customersRoute.show(customer.id).url },
    ];

    const [mergeOpen, setMergeOpen] = useState(false);
    const mergeForm = useForm({ secondary_customer_id: '' });

    function handleMerge(e: React.FormEvent) {
        e.preventDefault();
        mergeForm.post(customersRoute.merge(customer.id).url, {
            onSuccess: () => setMergeOpen(false),
        });
    }

    const [tierOpen, setTierOpen] = useState(false);
    const tierForm = useForm({
        tier: customer.tier.value,
        cumulative_spend: '' as string,
        reason: '',
    });

    function resetTierForm() {
        tierForm.reset();
        tierForm.setData('tier', customer.tier.value);
    }

    function handleTierOverride(e: React.FormEvent) {
        e.preventDefault();
        tierForm.patch(customersRoute.overrideTier(customer.id).url, {
            preserveScroll: true,
            onSuccess: () => setTierOpen(false),
        });
    }

    // لكل جدول اسم صفحته، والمعاملات تُبنى كاملةً في كل تنقّل فلا تسقط صفحة
    // الجدول الآخر من الرابط.
    function goToPage(key: 'loyaltyPage' | 'invoicePage', page: number) {
        const params: Record<string, string> = {
            loyaltyPage: String(loyaltyHistory.meta.current_page),
            invoicePage: String(invoiceHistory.meta.current_page),
        };

        params[key] = String(page);

        router.get(customersRoute.show(customer.id).url, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    // المحرّك يشتقّ المستوى من الإنفاق التراكمي عند كل فاتورة مسدَّدة — صعوداً
    // وهبوطاً معاً بعد تصحيح 20/08/2026 — فأيّ تعديل يدويٍّ يخالف ما يستحقّه
    // إنفاقه يُنقَض عند أول اكتساب، لا التنزيل وحده. نحذّر منه هنا، ونتيح
    // تصحيح الإنفاق في النموذج نفسه.
    const isOverride = tierForm.data.tier !== customer.tier.value;

    const creditUsedPct =
        financialSummary.creditLimit && financialSummary.creditLimit > 0
            ? Math.min(100, (financialSummary.totalOutstanding / financialSummary.creditLimit) * 100)
            : 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <div className="flex size-16 items-center justify-center rounded-full bg-muted">
                            {customer.customerType.value === 'corporate' ? (
                                <Building2 className="size-8 text-muted-foreground" />
                            ) : (
                                <User className="size-8 text-muted-foreground" />
                            )}
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-2xl font-bold">{customer.fullName}</h1>
                                <Badge
                                    variant="outline"
                                    className={TIER_COLORS[customer.tier.value]}
                                >
                                    {customer.tier.label}
                                </Badge>
                                {!customer.isActive && (
                                    <Badge variant="secondary">غير نشط</Badge>
                                )}
                            </div>
                            {customer.companyName && (
                                <p className="text-muted-foreground">{customer.companyName}</p>
                            )}
                            {customer.taxNumber && (
                                <p className="text-sm text-muted-foreground">
                                    الرقم الضريبي: <span dir="ltr">{customer.taxNumber}</span>
                                </p>
                            )}
                            <div className="mt-1 flex items-center gap-3">
                                <a
                                    href={`https://wa.me/${customer.phone}`}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="flex items-center gap-1.5 text-sm text-green-700 hover:underline"
                                >
                                    <Phone className="size-3.5" />
                                    <span dir="ltr">{customer.phone}</span>
                                </a>
                                {customer.email && (
                                    <span className="text-sm text-muted-foreground">{customer.email}</span>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={customersRoute.activity(customer.id).url}>
                                <Activity className="size-4" />
                                النشاط والتحليلات
                            </Link>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <a href={`https://wa.me/${customer.phone}`} target="_blank" rel="noreferrer">
                                <MessageCircle className="size-4" />
                                واتساب
                            </a>
                        </Button>
                        <Button size="sm" onClick={() => setMergeOpen(true)}>
                            <ArrowLeftRight className="size-4" />
                            دمج
                        </Button>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Financial Panel */}
                    <div className="lg:col-span-2 space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <CreditCard className="size-5" />
                                    الملخص المالي
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                                    <div className="rounded-lg bg-muted/40 p-4">
                                        <p className="text-sm text-muted-foreground">عدد الفواتير</p>
                                        <p className="mt-1 text-2xl font-bold tabular-nums">
                                            {financialSummary.invoiceCount}
                                        </p>
                                    </div>
                                    <div className="rounded-lg bg-muted/40 p-4">
                                        <p className="text-sm text-muted-foreground">إجمالي الفواتير</p>
                                        <p className="mt-1 text-lg font-bold tabular-nums" dir="ltr">
                                            {formatCurrency(financialSummary.totalBilled)}
                                        </p>
                                    </div>
                                    <div className="rounded-lg bg-muted/40 p-4">
                                        <p className="text-sm text-muted-foreground">إجمالي المدفوع</p>
                                        <p className="mt-1 text-lg font-bold tabular-nums text-green-700" dir="ltr">
                                            {formatCurrency(financialSummary.totalPaid)}
                                        </p>
                                    </div>
                                    <div className="rounded-lg bg-red-50 p-4">
                                        <p className="text-sm text-muted-foreground">المديونية</p>
                                        <p className="mt-1 text-lg font-bold tabular-nums text-destructive" dir="ltr">
                                            {formatCurrency(financialSummary.totalOutstanding)}
                                        </p>
                                    </div>
                                    <div className="rounded-lg bg-muted/40 p-4">
                                        <p className="text-sm text-muted-foreground">الحد الائتماني</p>
                                        <p className="mt-1 text-lg font-bold tabular-nums" dir="ltr">
                                            {financialSummary.creditLimit !== null
                                                ? formatCurrency(financialSummary.creditLimit)
                                                : <span className="text-sm text-muted-foreground">نقداً فقط</span>}
                                        </p>
                                    </div>
                                    {financialSummary.availableCredit !== null && (
                                        <div className="rounded-lg bg-muted/40 p-4">
                                            <p className="text-sm text-muted-foreground">الرصيد المتاح</p>
                                            <p className="mt-1 text-lg font-bold tabular-nums text-green-700" dir="ltr">
                                                {formatCurrency(financialSummary.availableCredit)}
                                            </p>
                                        </div>
                                    )}
                                </div>

                                {financialSummary.creditLimit !== null && financialSummary.creditLimit > 0 && (
                                    <div className="mt-4">
                                        <div className="mb-1.5 flex items-center justify-between text-sm">
                                            <span className="text-muted-foreground">استخدام الحد الائتماني</span>
                                            <span className="font-medium">{creditUsedPct.toFixed(0)}%</span>
                                        </div>
                                        <div className="h-2.5 w-full overflow-hidden rounded-full bg-muted">
                                            <div
                                                className={`h-full rounded-full transition-all ${creditUsedPct >= 90
                                                    ? 'bg-destructive'
                                                    : creditUsedPct >= 70
                                                        ? 'bg-amber-500'
                                                        : 'bg-green-500'
                                                    }`}
                                                style={{ width: `${creditUsedPct}%` }}
                                            />
                                        </div>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Invoice History */}
                        <Card>
                            <CardHeader>
                                <CardTitle>سجل الفواتير</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <DataTable
                                    className="rounded-none bg-transparent shadow-none"
                                    columns={invoiceHistoryColumns}
                                    data={invoiceHistory.data}
                                    keyExtractor={(inv) => `${inv.type}-${inv.id}`}
                                    emptyState={<span className="text-sm text-muted-foreground">لا توجد فواتير مسجّلة</span>}
                                />
                                {invoiceHistory.meta.total > 0 && (
                                    <TablePagination
                                        currentPage={invoiceHistory.meta.current_page}
                                        totalPages={invoiceHistory.meta.last_page}
                                        totalItems={invoiceHistory.meta.total}
                                        from={invoiceHistory.meta.from}
                                        to={invoiceHistory.meta.to}
                                        onPageChange={(page) => goToPage('invoicePage', page)}
                                    />
                                )}
                            </CardContent>
                        </Card>

                        {/* Notes */}
                        {customer.notes && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>ملاحظات</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="whitespace-pre-wrap text-sm text-muted-foreground">
                                        {customer.notes}
                                    </p>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Loyalty Panel */}
                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Star className="size-5" />
                                    برنامج الولاء
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex items-center justify-between">
                                    <span className="text-sm text-muted-foreground">المستوى</span>
                                    <Badge
                                        variant="outline"
                                        className={TIER_COLORS[customer.tier.value]}
                                    >
                                        {customer.tier.label}
                                    </Badge>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-sm text-muted-foreground">رصيد النقاط</span>
                                    <span className="font-bold tabular-nums">
                                        {formatNumber(customer.pointsBalance)}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-sm text-muted-foreground">الإنفاق التراكمي</span>
                                    <span className="font-medium tabular-nums" dir="ltr">
                                        {formatCurrency(Number(customer.cumulativeSpend))}
                                    </span>
                                </div>

                                {customer.agent && (
                                    <div className="flex items-center justify-between border-t pt-3">
                                        <span className="text-sm text-muted-foreground">المندوب</span>
                                        <span className="text-sm font-medium">{customer.agent.name}</span>
                                    </div>
                                )}

                                {canOverrideTier && (
                                    <div className="border-t pt-3">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="w-full"
                                            onClick={() => setTierOpen(true)}
                                        >
                                            <Pencil className="size-4" />
                                            تعديل المستوى يدوياً
                                        </Button>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Loyalty History */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <TrendingUp className="size-5" />
                                    سجل النقاط
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="px-0">
                                {loyaltyHistory.meta.total === 0 ? (
                                    <p className="text-center text-sm text-muted-foreground py-6">
                                        لا توجد معاملات نقاط
                                    </p>
                                ) : (
                                    <div className="space-y-2 px-6">
                                        {loyaltyHistory.data.map((tx) => (
                                            <div
                                                key={tx.id}
                                                className="flex items-center justify-between rounded-lg bg-muted/40 px-3 py-2 text-sm"
                                            >
                                                <div>
                                                    <span
                                                        className={`font-bold tabular-nums ${tx.points > 0 ? 'text-green-700' : 'text-destructive'
                                                            }`}
                                                    >
                                                        {tx.points > 0 ? '+' : ''}
                                                        {tx.points}
                                                    </span>
                                                    {tx.notes && (
                                                        <p className="text-xs text-muted-foreground">{tx.notes}</p>
                                                    )}
                                                </div>
                                                <span className="text-xs text-muted-foreground">
                                                    {formatDate(tx.created_at)}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                                {loyaltyHistory.meta.total > 0 && (
                                    <TablePagination
                                        className="mt-3"
                                        currentPage={loyaltyHistory.meta.current_page}
                                        totalPages={loyaltyHistory.meta.last_page}
                                        totalItems={loyaltyHistory.meta.total}
                                        from={loyaltyHistory.meta.from}
                                        to={loyaltyHistory.meta.to}
                                        onPageChange={(page) => goToPage('loyaltyPage', page)}
                                    />
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>

            {/* Manual tier override — the engine re-derives the tier from spend both ways */}
            <Dialog
                open={tierOpen}
                onOpenChange={(open) => {
                    setTierOpen(open);
                    if (!open) resetTierForm();
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تعديل مستوى الولاء</DialogTitle>
                        <DialogDescription>
                            النظام يشتقّ المستوى من الإنفاق التراكمي صعوداً وهبوطاً، فما يُضبط هنا
                            لا يثبت إلا إذا وافقه الإنفاق. يُحفظ السبب في سجلّ العميل.
                        </DialogDescription>
                    </DialogHeader>

                    <form onSubmit={handleTierOverride} className="space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="tier">المستوى</Label>
                            <Select
                                value={tierForm.data.tier}
                                onValueChange={(value) => tierForm.setData('tier', value as CustomerTier)}
                            >
                                <SelectTrigger id="tier">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {TIER_OPTIONS.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {tierForm.errors.tier && (
                                <p className="text-sm text-destructive">{tierForm.errors.tier}</p>
                            )}
                        </div>

                        {isOverride && (
                            <div className="flex gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
                                <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                                <span>
                                    الإنفاق التراكمي الحالي{' '}
                                    <span dir="ltr" className="font-medium">
                                        {formatCurrency(Number(customer.cumulativeSpend))}
                                    </span>{' '}
                                    هو ما يُشتقّ منه المستوى عند كل فاتورة مسدَّدة، صعوداً وهبوطاً. صحّحه أدناه وإلا
                                    عاد العميل إلى ما يستحقّه إنفاقه عند أول عملية شراء.
                                </span>
                            </div>
                        )}

                        <div className="space-y-2">
                            <Label htmlFor="cumulative_spend">
                                الإنفاق التراكمي <span className="text-muted-foreground">(اختياري)</span>
                            </Label>
                            <Input
                                id="cumulative_spend"
                                type="number"
                                step="0.01"
                                min="0"
                                dir="ltr"
                                placeholder={String(customer.cumulativeSpend)}
                                value={tierForm.data.cumulative_spend}
                                onChange={(e) => tierForm.setData('cumulative_spend', e.target.value)}
                            />
                            <p className="text-xs text-muted-foreground">
                                اتركه فارغاً ليبقى كما هو.
                            </p>
                            {tierForm.errors.cumulative_spend && (
                                <p className="text-sm text-destructive">{tierForm.errors.cumulative_spend}</p>
                            )}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="reason">السبب</Label>
                            <Input
                                id="reason"
                                value={tierForm.data.reason}
                                onChange={(e) => tierForm.setData('reason', e.target.value)}
                                placeholder="مثال: تصحيح مستوى نتج عن فواتير مرتجعة"
                            />
                            {tierForm.errors.reason && (
                                <p className="text-sm text-destructive">{tierForm.errors.reason}</p>
                            )}
                        </div>

                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    setTierOpen(false);
                                    resetTierForm();
                                }}
                            >
                                إلغاء
                            </Button>
                            <Button type="submit" disabled={tierForm.processing || !tierForm.data.reason}>
                                حفظ
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Merge Dialog */}
            <Dialog open={mergeOpen} onOpenChange={(open) => { setMergeOpen(open); mergeForm.reset(); }}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>دمج العميل</DialogTitle>
                        <DialogDescription>
                            سيتم نقل جميع فواتير ونقاط العميل الثانوي إلى{' '}
                            <strong>{customer.fullName}</strong> ثم حذف العميل الثانوي نهائياً.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={handleMerge}>
                        <div className="space-y-2 py-4">
                            <Label htmlFor="secondary_customer_id">
                                معرّف العميل الثانوي (الذي سيُدمج ويُحذف)
                            </Label>

                            <Select>
                                <SelectTrigger>
                                    <SelectValue placeholder="اختر العميل" />
                                </SelectTrigger>
                                <SelectContent>
                                    {customers.map((customer: Customer) => (
                                        <SelectItem key={customer.id} value={customer.id.toString()}>
                                            {customer.fullName}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

                            {/* <Input
                                id="secondary_customer_id"
                                type="number"
                                min="1"
                                value={mergeForm.data.secondary_customer_id}
                                onChange={(e) =>
                                    mergeForm.setData('secondary_customer_id', e.target.value)
                                }
                                placeholder="أدخل المعرّف"
                                dir="ltr"
                            /> */}
                            {mergeForm.errors.secondary_customer_id && (
                                <p className="text-sm text-destructive">
                                    {mergeForm.errors.secondary_customer_id}
                                </p>
                            )}
                        </div>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                type="button"
                                onClick={() => { setMergeOpen(false); mergeForm.reset(); }}
                            >
                                إلغاء
                            </Button>
                            <Button
                                variant="destructive"
                                type="submit"
                                disabled={mergeForm.processing || !mergeForm.data.secondary_customer_id}
                            >
                                تأكيد الدمج
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
