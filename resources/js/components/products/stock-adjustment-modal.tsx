import { store } from '@/actions/App/Http/Controllers/StockMovementController';
import { Button } from '@/components/ui/button';
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
import { type Product } from '@/types/product';
import { MANUAL_MOVEMENT_TYPES, type StockMovementType } from '@/types/stock-movement';
import { useForm } from '@inertiajs/react';
import { toast } from 'sonner';
import InputError from '../input-error';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    product?: Product;
}

export default function StockAdjustmentModal({ open, onOpenChange, product }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm({
        product_id: product?.id ?? 0,
        type: 'opening_stock' as StockMovementType,
        qty: '',
        unit_cost: '',
        notes: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (!product) return;

        post(store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                reset();
                toast.success('تم تسجيل حركة المخزون بنجاح');
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>تسوية المخزون</DialogTitle>
                    <DialogDescription>
                        {product ? `${product.name} — المخزون الحالي: ${product.currentStock}` : ''}
                    </DialogDescription>
                </DialogHeader>

                <form id="stock-adjustment-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="space-y-1">
                        <Label htmlFor="sm-type">نوع الحركة</Label>
                        <Select value={data.type} onValueChange={(val) => setData('type', val as StockMovementType)}>
                            <SelectTrigger id="sm-type">
                                <SelectValue placeholder="اختر النوع" />
                            </SelectTrigger>
                            <SelectContent>
                                {MANUAL_MOVEMENT_TYPES.map((t) => (
                                    <SelectItem key={t.value} value={t.value}>
                                        {t.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.type} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="sm-qty">الكمية</Label>
                        <Input
                            id="sm-qty"
                            type="number"
                            min="1"
                            value={data.qty}
                            onChange={(e) => setData('qty', e.target.value)}
                            placeholder="0"
                            dir="ltr"
                            autoFocus
                        />
                        <InputError message={errors.qty} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="sm-unit-cost">سعر التكلفة للوحدة (ر.س)</Label>
                        <Input
                            id="sm-unit-cost"
                            type="number"
                            min="0"
                            step="0.01"
                            value={data.unit_cost}
                            onChange={(e) => setData('unit_cost', e.target.value)}
                            placeholder="اختياري"
                            dir="ltr"
                        />
                        <InputError message={errors.unit_cost} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="sm-notes">ملاحظات</Label>
                        <Input
                            id="sm-notes"
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                            placeholder="اختياري"
                        />
                        <InputError message={errors.notes} />
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="stock-adjustment-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : 'تسجيل'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
