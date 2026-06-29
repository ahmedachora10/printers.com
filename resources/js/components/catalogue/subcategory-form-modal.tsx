import subcategoryRoutes from '@/actions/App/Http/Controllers/CatalogSubcategoryController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ImageUpload } from '@/components/ui/image-upload';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type CatalogSubcategory } from '@/types/catalogue';
import { router, useForm } from '@inertiajs/react';
import { useEffect } from 'react';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    categoryId: number;
    subcategory?: CatalogSubcategory;
}

export default function SubcategoryFormModal({ open, onOpenChange, categoryId, subcategory }: Props) {
    const isEdit = !!subcategory;

    const { data, setData, post, processing, errors, reset, setError, clearErrors } = useForm<{
        category_id: number;
        name_ar: string;
        sort_order: number;
        is_active: boolean;
        image: File | null;
    }>({
        category_id: categoryId,
        name_ar: subcategory?.nameAr ?? '',
        sort_order: subcategory?.sortOrder ?? 0,
        is_active: subcategory?.isActive ?? true,
        image: null,
    });

    useEffect(() => {
        setData({
            category_id: categoryId,
            name_ar: subcategory?.nameAr ?? '',
            sort_order: subcategory?.sortOrder ?? 0,
            is_active: subcategory?.isActive ?? true,
            image: null,
        });
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [subcategory, open]);

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
            router.post(subcategoryRoutes.update.url(subcategory), { ...data }, options);
        } else {
            post(subcategoryRoutes.store.url(), options);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'تعديل خدمة فرعية' : 'إضافة خدمة فرعية'}</DialogTitle>
                </DialogHeader>

                <form id="subcategory-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="space-y-1">
                        <Label>الصورة</Label>
                        <ImageUpload
                            value={data.image}
                            onChange={(file) => setData('image', file)}
                            currentUrl={isEdit ? (subcategory.imageUrl ?? undefined) : undefined}
                            error={errors.image}
                        />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="sub-name">الاسم</Label>
                        <Input
                            id="sub-name"
                            value={data.name_ar}
                            onChange={(e) => setData('name_ar', e.target.value)}
                            placeholder="أدخل اسم الخدمة الفرعية"
                            autoFocus
                        />
                        <InputError message={errors.name_ar} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="sub-sort">الترتيب</Label>
                        <Input
                            id="sub-sort"
                            type="number"
                            min={0}
                            value={data.sort_order}
                            onChange={(e) => setData('sort_order', Number(e.target.value))}
                        />
                        <InputError message={errors.sort_order} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="sub-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="sub-active" className="cursor-pointer">
                            نشطة
                        </Label>
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="subcategory-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : isEdit ? 'حفظ التعديلات' : 'إضافة'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
