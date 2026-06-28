import { store, update } from '@/actions/App/Http/Controllers/ExpenseCategoryController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type ExpenseCategory } from '@/types/expense-category';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import InputError from '../input-error';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    expenseCategory?: ExpenseCategory;
}

export default function ExpenseCategoryFormModal({ open, onOpenChange, expenseCategory }: Props) {
    const isEdit = !!expenseCategory;

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: expenseCategory?.name ?? '',
        is_active: expenseCategory?.isActive ?? true,
    });

    useEffect(() => {
        if (expenseCategory) {
            setData({
                name: expenseCategory.name ?? '',
                is_active: expenseCategory.isActive ?? true,
            });
        } else {
            reset();
        }
    }, [expenseCategory, open]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        if (isEdit) {
            put(update.url(expenseCategory), {
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
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'تعديل فئة مصروف' : 'إضافة فئة مصروف'}</DialogTitle>
                </DialogHeader>

                <form id="expense-category-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="space-y-1">
                        <Label htmlFor="ec-name">اسم الفئة</Label>
                        <Input
                            id="ec-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="أدخل اسم الفئة"
                            autoFocus
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="ec-is-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="ec-is-active" className="cursor-pointer">
                            نشطة
                        </Label>
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
                    <Button type="submit" form="expense-category-form" disabled={processing}>
                        {processing
                            ? 'جاري الحفظ...'
                            : isEdit
                                ? 'حفظ التعديلات'
                                : 'إضافة'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
