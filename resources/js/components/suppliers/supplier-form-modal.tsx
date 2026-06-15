import { store, update } from '@/actions/App/Http/Controllers/SupplierController';
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
import { type Supplier } from '@/types/supplier';
import { useForm } from '@inertiajs/react';
import InputError from '../input-error';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    supplier?: Supplier;
}

export default function SupplierFormModal({ open, onOpenChange, supplier }: Props) {
    const isEdit = !!supplier;

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: supplier?.name ?? '',
        phone: supplier?.phone ?? '',
        email: supplier?.email ?? '',
        notes: supplier?.notes ?? '',
        is_active: supplier?.isActive ?? true,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        if (isEdit) {
            put(update.url(supplier), {
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
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'تعديل مورد' : 'إضافة مورد'}</DialogTitle>
                </DialogHeader>

                <form id="supplier-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="space-y-1">
                        <Label htmlFor="sup-name">اسم المورد</Label>
                        <Input
                            id="sup-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="أدخل اسم المورد"
                            autoFocus
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="sup-phone">الهاتف</Label>
                            <Input
                                id="sup-phone"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                                placeholder="اختياري"
                                dir="ltr"
                            />
                            <InputError message={errors.phone} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="sup-email">البريد الإلكتروني</Label>
                            <Input
                                id="sup-email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="اختياري"
                                dir="ltr"
                            />
                            <InputError message={errors.email} />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="sup-notes">ملاحظات</Label>
                        <textarea
                            id="sup-notes"
                            rows={3}
                            value={data.notes}
                            onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setData('notes', e.target.value)}
                            placeholder="اختياري"
                            className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        />
                        <InputError message={errors.notes} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="sup-is-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="sup-is-active" className="cursor-pointer">
                            نشط
                        </Label>
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="supplier-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : isEdit ? 'حفظ التعديلات' : 'إضافة'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
