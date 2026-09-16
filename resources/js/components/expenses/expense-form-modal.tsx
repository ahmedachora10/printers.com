import { store, update } from '@/actions/App/Http/Controllers/ExpenseController';
import { AsyncCombobox, type AsyncOption } from '@/components/ui/async-combobox';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type Expense, type ExpenseSource } from '@/types/expense';
import { useForm } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import InputError from '../input-error';

interface Category {
    id: number;
    name: string;
    /** null = فئة عامة (تاسك 102). */
    branchId: number | null;
}

interface Branch {
    id: number;
    name: string;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    expense?: Expense;
    categories: Category[];
    branches?: Branch[] | null;
}

const SOURCES: { value: ExpenseSource; label: string }[] = [
    { value: 'cash_drawer', label: 'نقد من الكاشير' },
    { value: 'company_transfer', label: 'تحويل بنكي من حساب الشركة' },
];

const FIELD_LABELS: Record<string, string> = {
    expense_category_id: 'الفئة (رقم)',
    qty: 'الكمية',
    unit_price: 'سعر الوحدة',
    total: 'الإجمالي',
    paid_from: 'مصدر الدفع',
    supplier_name: 'المورّد',
    receipt_reference: 'مرجع الإيصال',
    comment: 'الملاحظات',
    date: 'التاريخ',
    service_invoice_id: 'الفاتورة المربوطة (رقم)',
};

const NO_INVOICE: AsyncOption = { value: 'none', label: '— بلا ربط —' };

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

export default function ExpenseFormModal({ open, onOpenChange, expense, categories, branches }: Props) {
    const isEdit = !!expense;
    const isSuperAdmin = Array.isArray(branches);

    const { data, setData, post, transform, processing, errors, reset } = useForm({
        branch_id: expense?.branchId?.toString() ?? (branches?.[0]?.id?.toString() ?? ''),
        expense_category_id: expense?.expenseCategoryId?.toString() ?? '',
        qty:                 expense?.qty?.toString() ?? '1',
        unit_price:          expense?.unitPrice?.toString() ?? '',
        // تاسك 110: بلا اختيار مسبق — المستخدم يحدّد المصدر صراحةً.
        paid_from:           expense?.paidFrom ?? '',
        supplier_name:       expense?.supplierName ?? '',
        receipt_reference:   expense?.receiptReference ?? '',
        comment:             expense?.comment ?? '',
        date:                expense?.date ?? todayIso(),
        // تاسك 112
        service_invoice_id:  expense?.serviceInvoiceId?.toString() ?? '',
        attachment:          null as File | null,
    });
    const [invoiceLabel, setInvoiceLabel] = useState(expense?.invoiceNumber ?? '');

    useEffect(() => {
        if (expense) {
            setData({
                branch_id:           expense.branchId?.toString() ?? (branches?.[0]?.id?.toString() ?? ''),
                expense_category_id: expense.expenseCategoryId?.toString() ?? '',
                qty:                 expense.qty?.toString() ?? '1',
                unit_price:          expense.unitPrice?.toString() ?? '',
                paid_from:           expense.paidFrom,
                supplier_name:       expense.supplierName ?? '',
                receipt_reference:   expense.receiptReference ?? '',
                comment:             expense.comment ?? '',
                date:                expense.date ?? todayIso(),
                service_invoice_id:  expense.serviceInvoiceId?.toString() ?? '',
                attachment:          null,
            });
            setInvoiceLabel(expense.invoiceNumber ?? '');
        } else {
            reset();
        }
    }, [expense, open]);

    // السوبر أدمن يستلم فئات كل الفروع؛ يُعرض منها العام وما يخصّ الفرع المختار.
    const branchCategories = isSuperAdmin ? categories.filter((c) => c.branchId === null || c.branchId.toString() === data.branch_id) : categories;

    // فواتير خدمات فرع المصروف — السوبر أدمن يمرّر الفرع المختار في النافذة.
    const fetchInvoices = useCallback(
        async (q: string): Promise<AsyncOption[]> => {
            const params = new URLSearchParams({ q, branch_id: data.branch_id });
            const res = await fetch(`/expenses/invoice-options?${params}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!res.ok) return [];
            const json = (await res.json()) as { data: { id: number; invoiceNumber: string; customerName: string | null; date: string }[] };
            return json.data.map((i) => ({ value: String(i.id), label: `${i.invoiceNumber} — ${i.customerName ?? 'عميل نقدي'} — ${i.date}`, data: i }));
        },
        [data.branch_id],
    );

    const total = (parseFloat(data.qty || '0') * parseFloat(data.unit_price || '0') || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        if (isEdit) {
            // الملفّ multipart، وPHP لا يقرأ ملفات PUT — فـPOST بـ_method.
            transform((d) => ({ ...d, _method: 'put' }));
            post(update.url(expense), {
                preserveScroll: true,
                onSuccess: () => { onOpenChange(false); reset(); },
            });
        } else {
            post(store.url(), {
                preserveScroll: true,
                onSuccess: () => { onOpenChange(false); reset(); },
            });
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'تعديل مصروف' : 'تسجيل مصروف'}</DialogTitle>
                </DialogHeader>

                <form id="expense-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    {isSuperAdmin && (
                        <div className="space-y-1">
                            <Label htmlFor="exp-branch">الفرع</Label>
                            <Select
                                value={data.branch_id}
                                onValueChange={(val) => setData((d) => ({ ...d, branch_id: val, expense_category_id: '' }))}
                                disabled={isEdit}
                            >
                                <SelectTrigger id="exp-branch">
                                    <SelectValue placeholder="اختر الفرع" />
                                </SelectTrigger>
                                <SelectContent>
                                    {branches!.map((b) => (
                                        <SelectItem key={b.id} value={b.id.toString()}>
                                            {b.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.branch_id} />
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="exp-category">الفئة</Label>
                            <Select
                                value={data.expense_category_id}
                                onValueChange={(val) => setData('expense_category_id', val)}
                            >
                                <SelectTrigger id="exp-category">
                                    <SelectValue placeholder="اختر الفئة" />
                                </SelectTrigger>
                                <SelectContent>
                                    {branchCategories.map((c) => (
                                        <SelectItem key={c.id} value={c.id.toString()}>
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.expense_category_id} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="exp-date">التاريخ</Label>
                            <Input
                                id="exp-date"
                                type="date"
                                value={data.date}
                                onChange={(e) => setData('date', e.target.value)}
                                dir="ltr"
                            />
                            <InputError message={errors.date} />
                        </div>
                    </div>

                    <div className="grid grid-cols-3 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="exp-qty">الكمية</Label>
                            <Input
                                id="exp-qty"
                                type="number"
                                min="0.01"
                                step="0.01"
                                value={data.qty}
                                onChange={(e) => setData('qty', e.target.value)}
                                placeholder="0"
                                dir="ltr"
                            />
                            <InputError message={errors.qty} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="exp-unit-price">سعر الوحدة (ر.س)</Label>
                            <Input
                                id="exp-unit-price"
                                type="number"
                                min="0"
                                step="0.01"
                                value={data.unit_price}
                                onChange={(e) => setData('unit_price', e.target.value)}
                                placeholder="0.00"
                                dir="ltr"
                            />
                            <InputError message={errors.unit_price} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="exp-total">الإجمالي (ر.س)</Label>
                            <Input
                                id="exp-total"
                                value={total}
                                readOnly
                                disabled
                                dir="ltr"
                                className="font-medium"
                            />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label>مصدر الدفع</Label>
                        <div className="grid grid-cols-2 gap-2">
                            {SOURCES.map((s) => (
                                <Button
                                    key={s.value}
                                    type="button"
                                    variant={data.paid_from === s.value ? 'default' : 'outline'}
                                    aria-pressed={data.paid_from === s.value}
                                    onClick={() => setData('paid_from', s.value)}
                                >
                                    {s.label}
                                </Button>
                            ))}
                        </div>
                        <InputError message={errors.paid_from} />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="exp-supplier">المورّد</Label>
                            <Input
                                id="exp-supplier"
                                value={data.supplier_name}
                                onChange={(e) => setData('supplier_name', e.target.value)}
                                placeholder="اختياري"
                            />
                            <InputError message={errors.supplier_name} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="exp-receipt">مرجع الإيصال</Label>
                            <Input
                                id="exp-receipt"
                                value={data.receipt_reference}
                                onChange={(e) => setData('receipt_reference', e.target.value)}
                                placeholder="اختياري"
                                dir="ltr"
                            />
                            <InputError message={errors.receipt_reference} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1">
                            <Label>ربط بفاتورة/طلب</Label>
                            <AsyncCombobox
                                fetcher={fetchInvoices}
                                value={data.service_invoice_id || NO_INVOICE.value}
                                selectedLabel={invoiceLabel}
                                onChange={(value, option) => {
                                    setData('service_invoice_id', value === NO_INVOICE.value ? '' : value);
                                    setInvoiceLabel(option?.label.split(' — ')[0] ?? '');
                                }}
                                sentinel={NO_INVOICE}
                                searchPlaceholder="رقم الفاتورة أو اسم العميل"
                                emptyText="لا توجد فاتورة مطابقة"
                                triggerClassName="w-full"
                                className="w-[var(--radix-popover-trigger-width)] min-w-64"
                            />
                            <InputError message={errors.service_invoice_id} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="exp-attachment">المرفق</Label>
                            <Input
                                id="exp-attachment"
                                type="file"
                                accept="image/jpeg,image/png,image/webp,application/pdf"
                                onChange={(e) => setData('attachment', e.target.files?.[0] ?? null)}
                            />
                            <InputError message={errors.attachment} />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="exp-comment">ملاحظات</Label>
                        <textarea
                            id="exp-comment"
                            rows={2}
                            value={data.comment}
                            onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setData('comment', e.target.value)}
                            placeholder="اختياري"
                            className="flex min-h-[64px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        />
                        <InputError message={errors.comment} />
                    </div>
                    {/* تاسك 113: تعديلات ما بعد الاعتماد — القديم ⇒ الجديد، من ومتى. */}
                    {expense && expense.history.length > 0 && (
                        <div className="space-y-2 border-t pt-3">
                            <p className="text-sm font-semibold">سجلّ التعديلات بعد الاعتماد</p>
                            {expense.history.map((entry) => (
                                <div key={entry.id} className="text-muted-foreground text-xs">
                                    <p className="font-medium">
                                        {entry.byName ?? '—'} — {entry.at}
                                    </p>
                                    {Object.keys(entry.new).map((field) => (
                                        <p key={field}>
                                            {FIELD_LABELS[field] ?? field}: {String(entry.old[field] ?? '—')} ⇐ {String(entry.new[field] ?? '—')}
                                        </p>
                                    ))}
                                </div>
                            ))}
                        </div>
                    )}
                </form>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        إلغاء
                    </Button>
                    <Button type="submit" form="expense-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : isEdit ? 'حفظ التعديلات' : 'تسجيل'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
