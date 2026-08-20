import priceRoutes from '@/actions/App/Http/Controllers/CatalogPriceController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { BranchScopeField } from '@/components/catalogue/scope';
import { type CatalogPrice, type CatalogueBranchOption } from '@/types/catalogue';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    subcategoryId: number;
    price?: CatalogPrice;
    /** Super admin only: pick the owning branch, or null for a general price. */
    branches?: CatalogueBranchOption[] | null;
    defaultBranchId?: number | null;
}

export default function PriceFormModal({
    open,
    onOpenChange,
    subcategoryId,
    price,
    branches = null,
    defaultBranchId = null,
}: Props) {
    const isEdit = !!price;

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm<{
        subcategory_id: number;
        branch_id: number | null;
        name: string;
        min_price: number;
        max_price: number;
        base_price: number;
        sort_order: number;
        is_active: boolean;
    }>({
        subcategory_id: subcategoryId,
        branch_id: price?.branchId ?? defaultBranchId,
        name: price?.name ?? '',
        min_price: price?.minPrice ?? 0,
        max_price: price?.maxPrice ?? 0,
        base_price: price?.basePrice ?? 0,
        sort_order: price?.sortOrder ?? 0,
        is_active: price?.isActive ?? true,
    });

    useEffect(() => {
        setData({
            subcategory_id: subcategoryId,
            branch_id: price?.branchId ?? defaultBranchId,
            name: price?.name ?? '',
            min_price: price?.minPrice ?? 0,
            max_price: price?.maxPrice ?? 0,
            base_price: price?.basePrice ?? 0,
            sort_order: price?.sortOrder ?? 0,
            is_active: price?.isActive ?? true,
        });
        clearErrors();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [price, open]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                reset();
            },
        };

        if (isEdit) {
            put(priceRoutes.update.url(price), options);
        } else {
            post(priceRoutes.store.url(), options);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'تعديل بند سعر' : 'إضافة بند سعر'}</DialogTitle>
                </DialogHeader>

                <form id="price-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    {/* A row never changes owner, so the picker only shows while creating. */}
                    {!isEdit && (
                        <BranchScopeField
                            id="price-branch"
                            branches={branches}
                            value={data.branch_id}
                            onChange={(branchId) => setData('branch_id', branchId)}
                            error={errors.branch_id}
                            hint="سعر الفرع يعلو السعر العام حين يحملان الاسم نفسه."
                        />
                    )}

                    <div className="space-y-1">
                        <Label htmlFor="price-name">الاسم</Label>
                        <Input
                            id="price-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="مثال: طباعة A4 ملون"
                            autoFocus
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid grid-cols-3 gap-3">
                        <div className="space-y-1">
                            <Label htmlFor="price-min">أقل سعر</Label>
                            <Input
                                id="price-min"
                                type="number"
                                step="0.01"
                                min={0}
                                value={data.min_price}
                                onChange={(e) => setData('min_price', Number(e.target.value))}
                            />
                            <InputError message={errors.min_price} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="price-max">أعلى سعر</Label>
                            <Input
                                id="price-max"
                                type="number"
                                step="0.01"
                                min={0}
                                value={data.max_price}
                                onChange={(e) => setData('max_price', Number(e.target.value))}
                            />
                            <InputError message={errors.max_price} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="price-base">السعر الأساسي</Label>
                            <Input
                                id="price-base"
                                type="number"
                                step="0.01"
                                min={0}
                                value={data.base_price}
                                onChange={(e) => setData('base_price', Number(e.target.value))}
                            />
                            <InputError message={errors.base_price} />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="price-sort">الترتيب</Label>
                        <Input
                            id="price-sort"
                            type="number"
                            min={0}
                            value={data.sort_order}
                            onChange={(e) => setData('sort_order', Number(e.target.value))}
                        />
                        <InputError message={errors.sort_order} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="price-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="price-active" className="cursor-pointer">
                            نشط
                        </Label>
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="price-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : isEdit ? 'حفظ التعديلات' : 'إضافة'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
