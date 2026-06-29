import categoryRoutes from '@/actions/App/Http/Controllers/CatalogCategoryController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ImageUpload } from '@/components/ui/image-upload';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type CatalogCategory } from '@/types/catalogue';
import { router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    category?: CatalogCategory;
}

export default function CategoryFormModal({ open, onOpenChange, category }: Props) {
    const isEdit = !!category;

    const { data, setData, post, processing, errors, reset, setError, clearErrors } = useForm<{
        name_ar: string;
        sort_order: number;
        is_active: boolean;
        image: File | null;
    }>({
        name_ar: category?.nameAr ?? '',
        sort_order: category?.sortOrder ?? 0,
        is_active: category?.isActive ?? true,
        image: null,
    });

    useEffect(() => {
        if (category) {
            setData({
                name_ar: category.nameAr ?? '',
                sort_order: category.sortOrder ?? 0,
                is_active: category.isActive ?? true,
                image: null,
            });
        } else {
            reset();
        }
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [category, open]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        const options = {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                clearErrors();
                onOpenChange(false);
                reset();
            },
            onError: (errs: Record<string, string>) =>
                Object.entries(errs).forEach(([k, v]) => setError(k as keyof typeof data, v)),
        };

        if (isEdit) {
            router.post(categoryRoutes.update.url(category), { ...data }, options);
        } else {
            post(categoryRoutes.store.url(), options);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'تعديل فئة' : 'إضافة فئة'}</DialogTitle>
                </DialogHeader>

                <form id="category-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="space-y-1">
                        <Label>الصورة</Label>
                        <ImageUpload
                            value={data.image}
                            onChange={(file) => setData('image', file)}
                            currentUrl={isEdit ? (category.imageUrl ?? undefined) : undefined}
                            error={errors.image}
                        />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="cat-name">اسم الفئة</Label>
                        <Input
                            id="cat-name"
                            value={data.name_ar}
                            onChange={(e) => setData('name_ar', e.target.value)}
                            placeholder="أدخل اسم الفئة"
                            autoFocus
                        />
                        <InputError message={errors.name_ar} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="cat-sort">الترتيب</Label>
                        <Input
                            id="cat-sort"
                            type="number"
                            min={0}
                            value={data.sort_order}
                            onChange={(e) => setData('sort_order', Number(e.target.value))}
                        />
                        <InputError message={errors.sort_order} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="cat-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="cat-active" className="cursor-pointer">
                            نشطة
                        </Label>
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="category-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : isEdit ? 'حفظ التعديلات' : 'إضافة'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
