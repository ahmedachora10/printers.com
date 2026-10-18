import { request, store, update } from '@/actions/App/Http/Controllers/ExpenseController';
import BankAccountField, { keepAccount } from '@/components/expenses/bank-account-field';
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
import { type BankAccountOption, type Expense, type ExpenseSource } from '@/types/expense';
import { type SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/react';
import { Paperclip, X } from 'lucide-react';
import { useCallback, useEffect } from 'react';
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
    /** تاسك 187: حسابات الشركة البنكية — تظهر تحت «تحويل بنكي». */
    bankAccounts: BankAccountOption[];
    branches?: Branch[] | null;
    /** تاسك 149: قيمٌ مسبقة لمصروفٍ جديد يُسجَّل من صفحة الفاتورة. */
    defaults?: { unit_price: string; invoice: LinkedInvoice };
    /** تاسك 157: الموظف يرسل طلباً يقبله المحاسب، والفاتورة ثابتة. */
    isRequest?: boolean;
}

/** تاسك 187: فاتورةٌ مربوطة وحصّتها — المبلغ يُكتب حين تُربط أكثر من فاتورة. */
type LinkedInvoice = {
    id: string;
    label: string;
    amount: string;
};

const SOURCES: { value: ExpenseSource; label: string }[] = [
    { value: 'cash_drawer', label: 'نقد من الكاشير' },
    { value: 'company_transfer', label: 'تحويل بنكي من حساب الشركة' },
];

const FIELD_LABELS: Record<string, string> = {
    expense_category_id: 'الفئة',
    qty: 'الكمية',
    unit_price: 'سعر الوحدة',
    total: 'الإجمالي',
    paid_from: 'مصدر الدفع',
    bank_account_id: 'الحساب البنكي',
    supplier_name: 'المورّد',
    receipt_reference: 'مرجع الإيصال',
    comment: 'الملاحظات',
    date: 'التاريخ',
    service_invoice_id: 'الفاتورة المربوطة',
    invoices: 'الفواتير المربوطة',
};

function money(value: number): string {
    return value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function todayIso(): string {
    // Local day, not UTC — after midnight in Riyadh UTC is still yesterday.
    return new Date().toLocaleDateString('en-CA');
}

function linkedOf(expense: Expense): LinkedInvoice[] {
    return expense.invoices.map((i) => ({ id: String(i.id), label: i.invoiceNumber, amount: String(i.amount) }));
}

export default function ExpenseFormModal({ open, onOpenChange, expense, categories, bankAccounts, branches, defaults, isRequest = false }: Props) {
    const isEdit = !!expense;
    // تاسك 186: من لا يملك التعديل (المحاسب بعد الاعتماد، تسوية التوصيل…) يرى التفاصيل للقراءة فقط.
    const readOnly = isEdit && !expense.canUpdate;
    const isSuperAdmin = Array.isArray(branches);
    // تاسك 135: التاريخ القديم لمدير الفرع ومدير النظام فقط (والتاريخ الحالي للمصروف يبقى).
    const { role } = usePage<SharedData>().props.auth;
    const canBackdate = role === 'super-admin' || role === 'branch-admin';

    const { data, setData, post, transform, processing, errors, reset } = useForm({
        branch_id: expense?.branchId?.toString() ?? (branches?.[0]?.id?.toString() ?? ''),
        expense_category_id: expense?.expenseCategoryId?.toString() ?? '',
        qty:                 expense?.qty?.toString() ?? '1',
        unit_price:          expense?.unitPrice?.toString() ?? defaults?.unit_price ?? '',
        // تاسك 110: بلا اختيار مسبق — المستخدم يحدّد المصدر صراحةً.
        paid_from:           expense?.paidFrom ?? '',
        bank_account_id:     expense?.bankAccountId?.toString() ?? '',
        supplier_name:       expense?.supplierName ?? '',
        receipt_reference:   expense?.receiptReference ?? '',
        comment:             expense?.comment ?? '',
        date:                expense?.date ?? todayIso(),
        // تاسك 112/187
        invoices:            expense ? linkedOf(expense) : defaults ? [defaults.invoice] : [],
        attachments:         [] as File[],
        remove_attachment_ids: [] as number[],
    });

    useEffect(() => {
        if (expense) {
            setData({
                branch_id:           expense.branchId?.toString() ?? (branches?.[0]?.id?.toString() ?? ''),
                expense_category_id: expense.expenseCategoryId?.toString() ?? '',
                qty:                 expense.qty?.toString() ?? '1',
                unit_price:          expense.unitPrice?.toString() ?? '',
                paid_from:           expense.paidFrom,
                bank_account_id:     expense.bankAccountId?.toString() ?? '',
                supplier_name:       expense.supplierName ?? '',
                receipt_reference:   expense.receiptReference ?? '',
                comment:             expense.comment ?? '',
                date:                expense.date ?? todayIso(),
                invoices:            linkedOf(expense),
                attachments:         [],
                remove_attachment_ids: [],
            });
        } else {
            reset();
        }
    }, [expense, open]);

    // السوبر أدمن يستلم فئات كل الفروع؛ يُعرض منها العام وما يخصّ الفرع المختار.
    const branchCategories = isSuperAdmin ? categories.filter((c) => c.branchId === null || c.branchId.toString() === data.branch_id) : categories;
    const branchAccounts = isSuperAdmin ? bankAccounts.filter((a) => a.branchId.toString() === data.branch_id) : bankAccounts;

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

    const totalValue = parseFloat(data.qty || '0') * parseFloat(data.unit_price || '0') || 0;
    const total = money(totalValue);
    // تاسك 187: أكثر من فاتورة ⇒ حصّةٌ لكلٍّ منها، ومجموعها = الإجمالي (يتحقّق منه الخادم).
    const splits = data.invoices.length > 1;
    const distributed = data.invoices.reduce((sum, i) => sum + (parseFloat(i.amount) || 0), 0);
    const keptAttachments = expense?.attachments.filter((a) => !data.remove_attachment_ids.includes(a.id)) ?? [];

    function addInvoice(value: string, label: string) {
        if (!value || data.invoices.some((i) => i.id === value)) return;
        setData('invoices', [...data.invoices, { id: value, label, amount: '' }]);
    }

    function setInvoiceAmount(index: number, amount: string) {
        setData('invoices', data.invoices.map((i, n) => (n === index ? { ...i, amount } : i)));
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        // الوسم للعرض وحده؛ الخادم يقرأ المعرّف والحصّة.
        const payload = (d: typeof data) => ({ ...d, invoices: d.invoices.map(({ id, amount }) => ({ id, amount })) });

        if (isEdit) {
            // الملفّ multipart، وPHP لا يقرأ ملفات PUT — فـPOST بـ_method.
            transform((d) => ({ ...payload(d), _method: 'put' }));
            post(update.url(expense), {
                preserveScroll: true,
                onSuccess: () => { onOpenChange(false); reset(); },
            });
        } else {
            transform(payload);
            post(isRequest ? request.url() : store.url(), {
                preserveScroll: true,
                onSuccess: () => { onOpenChange(false); reset(); },
            });
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{readOnly ? 'تفاصيل المصروف' : isEdit ? 'تعديل مصروف' : isRequest ? 'طلب تسجيل مصروف' : 'تسجيل مصروف'}</DialogTitle>
                    {isRequest && <p className="text-muted-foreground text-sm">يُرسل الطلب إلى المحاسب، ولا يُحتسب المصروف حتى يقبله.</p>}
                </DialogHeader>

                <form id="expense-form" onSubmit={handleSubmit}>
                    <fieldset disabled={readOnly} className="min-w-0 space-y-4 py-2">
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
                                min={canBackdate ? undefined : [expense?.date, todayIso()].filter(Boolean).sort()[0]}
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
                                    onClick={() => setData((d) => ({ ...d, paid_from: s.value, bank_account_id: keepAccount(s.value, d.bank_account_id) }))}
                                >
                                    {s.label}
                                </Button>
                            ))}
                        </div>
                        <InputError message={errors.paid_from} />
                    </div>

                    {/* تاسك 187: الحساب الذي خرج منه التحويل — إلزاميّ للتحويل. */}
                    {data.paid_from === 'company_transfer' && (
                        <BankAccountField accounts={branchAccounts} value={data.bank_account_id} onChange={(v) => setData('bank_account_id', v)} error={errors.bank_account_id} />
                    )}

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

                    {/* تاسك 187: فاتورة أو أكثر؛ طلب الموظف ثابتٌ على فاتورته. */}
                    <div className="space-y-2">
                        <Label>ربط بفاتورة/طلب</Label>
                        {data.invoices.map((invoice, index) => (
                            <div key={invoice.id} className="flex items-center gap-2">
                                <span className="min-w-0 flex-1 truncate text-sm" dir="ltr">
                                    {invoice.label}
                                </span>
                                {splits && (
                                    <Input
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        dir="ltr"
                                        className="w-28"
                                        aria-label={`حصّة ${invoice.label}`}
                                        placeholder="المبلغ"
                                        value={invoice.amount}
                                        onChange={(e) => setInvoiceAmount(index, e.target.value)}
                                    />
                                )}
                                {!isRequest && !readOnly && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="إزالة الفاتورة"
                                        onClick={() => setData('invoices', data.invoices.filter((_, n) => n !== index))}
                                    >
                                        <X className="size-4" />
                                    </Button>
                                )}
                            </div>
                        ))}
                        {splits && (
                            <p className={`text-xs tabular-nums ${Math.abs(distributed - totalValue) < 0.005 ? 'text-emerald-600' : 'text-amber-600'}`}>
                                الموزَّع {money(distributed)} من {total} ر.س
                            </p>
                        )}
                        {!isRequest && !readOnly && (
                            <AsyncCombobox
                                fetcher={fetchInvoices}
                                value=""
                                placeholder={data.invoices.length ? 'إضافة فاتورة أخرى…' : 'اختر فاتورة (اختياري)'}
                                onChange={(value, option) => addInvoice(value, option?.label.split(' — ')[0] ?? value)}
                                inline
                                searchPlaceholder="رقم الفاتورة أو اسم العميل"
                                emptyText="لا توجد فاتورة مطابقة"
                                triggerClassName="w-full"
                                className="w-[var(--radix-popover-trigger-width)] min-w-64"
                            />
                        )}
                        <InputError message={errors.invoices ?? Object.entries(errors).find(([k]) => k.startsWith('invoices.'))?.[1]} />
                    </div>

                    {/* تاسك 187: عدّة مرفقات — القائم يُحذف بعلامة، والجديد يُعاين ويُزال قبل الحفظ. */}
                    <div className="space-y-2">
                        <Label htmlFor="exp-attachments">المرفقات</Label>
                        {keptAttachments.map((a) => (
                            <div key={a.id} className="flex items-center gap-2 text-sm">
                                <Paperclip className="text-muted-foreground size-4 shrink-0" />
                                <a href={a.url} target="_blank" rel="noopener noreferrer" className="text-primary min-w-0 flex-1 truncate hover:underline">
                                    {a.name}
                                </a>
                                {!readOnly && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="حذف المرفق"
                                        onClick={() => setData('remove_attachment_ids', [...data.remove_attachment_ids, a.id])}
                                    >
                                        <X className="size-4" />
                                    </Button>
                                )}
                            </div>
                        ))}
                        {data.attachments.map((file, index) => (
                            <div key={`${file.name}-${index}`} className="flex items-center gap-2 text-sm">
                                <Paperclip className="size-4 shrink-0 text-emerald-600" />
                                <a href={URL.createObjectURL(file)} target="_blank" rel="noopener noreferrer" className="min-w-0 flex-1 truncate hover:underline">
                                    {file.name}
                                </a>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    aria-label="إزالة الملف"
                                    onClick={() => setData('attachments', data.attachments.filter((_, n) => n !== index))}
                                >
                                    <X className="size-4" />
                                </Button>
                            </div>
                        ))}
                        {readOnly && keptAttachments.length === 0 && <p className="text-muted-foreground text-sm">لا توجد مرفقات</p>}
                        {!readOnly && (
                            <Input
                                id="exp-attachments"
                                type="file"
                                multiple
                                accept="image/jpeg,image/png,image/webp,application/pdf"
                                onChange={(e) => {
                                    setData('attachments', [...data.attachments, ...Array.from(e.target.files ?? [])]);
                                    e.target.value = '';
                                }}
                            />
                        )}
                        <InputError message={errors.attachments ?? Object.entries(errors).find(([k]) => k.startsWith('attachments.'))?.[1]} />
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
                    </fieldset>
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
                        {readOnly ? 'إغلاق' : 'إلغاء'}
                    </Button>
                    {!readOnly && (
                        <Button type="submit" form="expense-form" disabled={processing}>
                            {processing ? 'جاري الحفظ...' : isEdit ? 'حفظ التعديلات' : 'تسجيل'}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
