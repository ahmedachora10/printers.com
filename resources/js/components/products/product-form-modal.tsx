import { store, update } from '@/actions/App/Http/Controllers/ProductController';
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type Product } from '@/types/product';
import { type ProductUnit } from '@/types/product-unit';
import { useForm } from '@inertiajs/react';
import InputError from '../input-error';

interface Category {
    id: number;
    name: string;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    product?: Product;
    categories: Category[];
    units: ProductUnit[];
}

export default function ProductFormModal({ open, onOpenChange, product, categories, units }: Props) {
    const isEdit = !!product;

    const { data, setData, post, put, processing, errors, reset } = useForm({
        sku:             product?.sku ?? '',
        name:            product?.name ?? '',
        category_id:     product?.categoryId?.toString() ?? '',
        unit_id:         product?.unitId?.toString() ?? '',
        cost_price:      product?.costPrice?.toString() ?? '',
        selling_price:   product?.sellingPrice?.toString() ?? '',
        min_stock_level: product?.minStockLevel?.toString() ?? '0',
        barcode:         product?.barcode ?? '',
        is_active:       product?.isActive ?? true,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        if (isEdit) {
            put(update.url(product), {
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
                    <DialogTitle>{isEdit ? 'تعديل منتج' : 'إضافة منتج'}</DialogTitle>
                </DialogHeader>

                <form id="product-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="prod-sku">رمز SKU</Label>
                            <Input
                                id="prod-sku"
                                value={data.sku}
                                onChange={(e) => setData('sku', e.target.value.toUpperCase())}
                                placeholder="تلقائي إذا تُرك فارغاً"
                                dir="ltr"
                                className="font-mono tracking-widest uppercase"
                            />
                            <InputError message={errors.sku} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="prod-barcode">الباركود</Label>
                            <Input
                                id="prod-barcode"
                                value={data.barcode}
                                onChange={(e) => setData('barcode', e.target.value)}
                                placeholder="اختياري"
                                dir="ltr"
                                className="font-mono"
                            />
                            <InputError message={errors.barcode} />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="prod-name">اسم المنتج</Label>
                        <Input
                            id="prod-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="أدخل اسم المنتج"
                            autoFocus
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="prod-category">الفئة</Label>
                            <Select
                                value={data.category_id}
                                onValueChange={(val) => setData('category_id', val)}
                            >
                                <SelectTrigger id="prod-category">
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
                            <InputError message={errors.category_id} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="prod-unit">وحدة القياس</Label>
                            <Select
                                value={data.unit_id}
                                onValueChange={(val) => setData('unit_id', val)}
                            >
                                <SelectTrigger id="prod-unit">
                                    <SelectValue placeholder="اختر الوحدة" />
                                </SelectTrigger>
                                <SelectContent>
                                    {units.map((u) => (
                                        <SelectItem key={u.id} value={u.id.toString()}>
                                            {u.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.unit_id} />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="prod-cost">سعر التكلفة (ر.س)</Label>
                            <Input
                                id="prod-cost"
                                type="number"
                                min="0"
                                step="0.01"
                                value={data.cost_price}
                                onChange={(e) => setData('cost_price', e.target.value)}
                                placeholder="0.00"
                                dir="ltr"
                            />
                            <InputError message={errors.cost_price} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="prod-selling">سعر البيع (ر.س)</Label>
                            <Input
                                id="prod-selling"
                                type="number"
                                min="0"
                                step="0.01"
                                value={data.selling_price}
                                onChange={(e) => setData('selling_price', e.target.value)}
                                placeholder="0.00"
                                dir="ltr"
                            />
                            <InputError message={errors.selling_price} />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="prod-min-stock">الحد الأدنى للمخزون</Label>
                        <Input
                            id="prod-min-stock"
                            type="number"
                            min="0"
                            value={data.min_stock_level}
                            onChange={(e) => setData('min_stock_level', e.target.value)}
                            placeholder="0"
                            dir="ltr"
                        />
                        <InputError message={errors.min_stock_level} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="prod-is-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="prod-is-active" className="cursor-pointer">
                            نشط
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
                    <Button type="submit" form="product-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : isEdit ? 'حفظ التعديلات' : 'إضافة'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
