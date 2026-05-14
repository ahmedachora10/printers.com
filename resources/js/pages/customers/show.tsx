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
import { Agent, type BreadcrumbItem } from '@/types';
import {
    type Customer,
    type CustomerFinancialSummary,
    type InvoiceHistoryItem,
    type LoyaltyTransaction,
} from '@/types/customer';
import { router, useForm } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Building2,
    CreditCard,
    MessageCircle,
    Pencil,
    Phone,
    Star,
    TrendingUp,
    User,
} from 'lucide-react';
import { useState } from 'react';

const TIER_COLORS: Record<string, string> = {
    none: 'border-border bg-muted/60 text-muted-foreground',
    bronze: 'border-amber-200 bg-amber-50 text-amber-700',
    silver: 'border-slate-200 bg-slate-100 text-slate-700',
    gold: 'border-yellow-200 bg-yellow-50 text-yellow-700',
};

const STATUS_COLORS: Record<string, string> = {
    paid: 'border-green-200 bg-green-50 text-green-700',
    due: 'border-red-200 bg-red-50 text-red-700',
    cancelled: 'border-border bg-muted/60 text-muted-foreground',
};

const STATUS_LABELS: Record<string, string> = {
    paid: 'مدفوع',
    due: 'مستحق',
    cancelled: 'ملغي',
};

interface Props {
    customer: Customer;
    financialSummary: CustomerFinancialSummary;
    loyaltyHistory: LoyaltyTransaction[];
    invoiceHistory: InvoiceHistoryItem[];
    customers: Customer[];
}

export default function CustomerShow({
    customer,
    financialSummary,
    loyaltyHistory,
    invoiceHistory,
    customers,
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
                            <CardContent>
                                {invoiceHistory.length === 0 ? (
                                    <p className="text-center text-sm text-muted-foreground py-8">
                                        لا توجد فواتير مسجّلة
                                    </p>
                                ) : (
                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead>
                                                <tr className="border-b">
                                                    <th className="pb-2 text-start font-medium text-muted-foreground">
                                                        رقم الفاتورة
                                                    </th>
                                                    <th className="pb-2 text-start font-medium text-muted-foreground">
                                                        النوع
                                                    </th>
                                                    <th className="pb-2 text-start font-medium text-muted-foreground">
                                                        المبلغ
                                                    </th>
                                                    <th className="pb-2 text-start font-medium text-muted-foreground">
                                                        الحالة
                                                    </th>
                                                    <th className="pb-2 text-start font-medium text-muted-foreground">
                                                        التاريخ
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y">
                                                {invoiceHistory.map((inv) => (
                                                    <tr key={`${inv.type}-${inv.id}`} className="py-2">
                                                        <td className="py-2 font-mono text-xs">
                                                            {inv.invoice_number}
                                                        </td>
                                                        <td className="py-2">
                                                            <Badge variant="secondary" className="text-xs">
                                                                {inv.type === 'service' ? 'خدمة' : 'منتج'}
                                                            </Badge>
                                                        </td>
                                                        <td className="py-2 tabular-nums" dir="ltr">
                                                            {formatCurrency(Number(inv.total_amount))}
                                                        </td>
                                                        <td className="py-2">
                                                            <Badge
                                                                variant="outline"
                                                                className={STATUS_COLORS[inv.status]}
                                                            >
                                                                {STATUS_LABELS[inv.status]}
                                                            </Badge>
                                                        </td>
                                                        <td className="py-2 text-muted-foreground">
                                                            {formatDate(inv.created_at)}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
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
                                        <span className="text-sm text-muted-foreground">الوكيل</span>
                                        <span className="text-sm font-medium">{customer.agent.name}</span>
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
                            <CardContent>
                                {loyaltyHistory.length === 0 ? (
                                    <p className="text-center text-sm text-muted-foreground py-6">
                                        لا توجد معاملات نقاط
                                    </p>
                                ) : (
                                    <div className="space-y-2">
                                        {loyaltyHistory.slice(0, 10).map((tx) => (
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
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>

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
