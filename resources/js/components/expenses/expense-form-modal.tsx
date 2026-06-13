import { store, update } from '@/actions/App/Http/Controllers/ExpenseController';
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
import { type Expense } from '@/types/expense';
import { useForm } from '@inertiajs/react';
import InputError from '../input-error';

interface Category {
    id: number;
    name: string;
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

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

export default function ExpenseFormModal({ open, onOpenChange, expense, categories, branches }: Props) {
    const isEdit = !!expense;
    const isSuperAdmin = Array.isArray(branches);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        branch_id: expense?.branchId?.toString() ?? (branches?.[0]?.id?.toString() ?? ''),
        expense_category_id: expense?.expenseCategoryId?.toString() ?? '',
        qty:                 expense?.qty?.toString() ?? '1',
        unit_price:          expense?.unitPrice?.toString() ?? '',
        supplier_name:       expense?.supplierName ?? '',
        receipt_reference:   expense?.receiptReference ?? '',
        comment:             expense?.comment ?? '',
        date:                expense?.date ?? todayIso(),
    });

    const total = (parseFloat(data.qty || '0') * parseFloat(data.unit_price || '0') || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        if (isEdit) {
            put(update.url(expense), {
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
                                onValueChange={(val) => setData('branch_id', val)}
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
                                    {categories.map((c) => (
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
