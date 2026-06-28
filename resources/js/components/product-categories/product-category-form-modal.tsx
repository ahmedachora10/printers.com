import { store, update } from '@/actions/App/Http/Controllers/ProductCategoryController';
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
import { type ProductCategory } from '@/types/product-category';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import InputError from '../input-error';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    productCategory?: ProductCategory;
}

export default function ProductCategoryFormModal({ open, onOpenChange, productCategory }: Props) {
    const isEdit = !!productCategory;

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: productCategory?.name ?? '',
        is_active: productCategory?.isActive ?? true,
    });

    useEffect(() => {
        if (productCategory) {
            setData({
                name: productCategory.name ?? '',
                is_active: productCategory.isActive ?? true,
            });
        } else {
            reset();
        }
    }, [productCategory, open]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        if (isEdit) {
            put(update.url(productCategory), {
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
                    <DialogTitle>{isEdit ? 'تعديل فئة منتج' : 'إضافة فئة منتج'}</DialogTitle>
                </DialogHeader>

                <form id="product-category-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="space-y-1">
                        <Label htmlFor="pc-name">اسم الفئة</Label>
                        <Input
                            id="pc-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="أدخل اسم الفئة"
                            autoFocus
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="pc-is-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="pc-is-active" className="cursor-pointer">
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
                    <Button type="submit" form="product-category-form" disabled={processing}>
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
