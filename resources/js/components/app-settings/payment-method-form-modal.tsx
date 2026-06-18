import { store, update } from '@/actions/App/Http/Controllers/PaymentMethodController';
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
import { type PaymentMethod } from '@/types/payment-method';
import { useForm } from '@inertiajs/react';
import InputError from '../input-error';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    paymentMethod?: PaymentMethod;
}

export default function PaymentMethodFormModal({ open, onOpenChange, paymentMethod }: Props) {
    const isEdit = !!paymentMethod;

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name:                paymentMethod?.name ?? '',
        is_active:           paymentMethod?.isActive ?? true,
        requires_attachment: paymentMethod?.requiresAttachment ?? false,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        if (isEdit) {
            put(update.url(paymentMethod), {
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
                    <DialogTitle>{isEdit ? 'تعديل طريقة الدفع' : 'إضافة طريقة دفع'}</DialogTitle>
                </DialogHeader>

                <form id="payment-method-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="space-y-1">
                        <Label htmlFor="pm-name">اسم طريقة الدفع</Label>
                        <Input
                            id="pm-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="مثال: نقد، بطاقة بنكية"
                            autoFocus
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="pm-is-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="pm-is-active" className="cursor-pointer">
                            نشطة
                        </Label>
                    </div>

                    <div className="flex items-start gap-2">
                        <Checkbox
                            id="pm-requires-attachment"
                            checked={data.requires_attachment}
                            onCheckedChange={(checked) => setData('requires_attachment', checked === true)}
                        />
                        <div className="grid gap-0.5">
                            <Label htmlFor="pm-requires-attachment" className="cursor-pointer">
                                تتطلب إرفاق إيصال
                            </Label>
                            <p className="text-muted-foreground text-xs">
                                مثل التحويل البنكي — يُطلب رفع صورة أو ملف PDF للإيصال عند إصدار الفاتورة.
                            </p>
                        </div>
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
                    <Button type="submit" form="payment-method-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : isEdit ? 'حفظ التعديلات' : 'إضافة'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
