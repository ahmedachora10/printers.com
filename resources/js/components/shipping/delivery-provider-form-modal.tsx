import { store, update } from '@/actions/App/Http/Controllers/DeliveryProviderController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { DeliveryProvider, DeliveryProviderType, EnumOption, ShippingBranchOption } from '@/types/shipping';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    provider?: DeliveryProvider;
    types: EnumOption[];
    branches: ShippingBranchOption[];
    isSuperAdmin: boolean;
    /** الفرع المختار في الفلتر — بذرةٌ لحقل الفرع عند الإضافة. */
    defaultBranchId?: number | null;
}

export default function DeliveryProviderFormModal({
    open,
    onOpenChange,
    provider,
    types,
    branches,
    isSuperAdmin,
    defaultBranchId,
}: Props) {
    const isEdit = !!provider;

    const { data, setData, post, put, processing, errors, reset } = useForm({
        branch_id: provider?.branchId ?? defaultBranchId ?? ('' as number | ''),
        name: provider?.name ?? '',
        type: provider?.type.value ?? 'driver',
        phone: provider?.phone ?? '',
        notes: provider?.notes ?? '',
        is_active: provider?.isActive ?? true,
    });

    // إعادة البذر عند فتح النافذة على صفٍّ آخر — بغيرها يبقى النموذج على
    // بيانات الصفّ السابق في وضع التعديل.
    useEffect(() => {
        if (provider) {
            setData({
                branch_id: provider.branchId,
                name: provider.name ?? '',
                type: provider.type.value,
                phone: provider.phone ?? '',
                notes: provider.notes ?? '',
                is_active: provider.isActive,
            });
        } else {
            reset();
            setData('branch_id', defaultBranchId ?? ('' as number | ''));
        }
    }, [provider, open]);

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
            put(update.url(provider), options);
        } else {
            post(store.url(), options);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'تعديل مزوّد التوصيل' : 'إضافة سائق أو شركة توصيل'}</DialogTitle>
                </DialogHeader>

                <form id="delivery-provider-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    {/* الفرع للسوبر أدمن وحده عند الإضافة: مدير الفرع يُثبَّت فرعه
                        في الخادم، والفرع لا يُنقل بالتعديل. */}
                    {isSuperAdmin && !isEdit && (
                        <div className="space-y-1">
                            <Label htmlFor="dp-branch">الفرع</Label>
                            <Select
                                value={data.branch_id === '' ? undefined : String(data.branch_id)}
                                onValueChange={(v) => setData('branch_id', Number(v))}
                            >
                                <SelectTrigger id="dp-branch">
                                    <SelectValue placeholder="اختر الفرع" />
                                </SelectTrigger>
                                <SelectContent>
                                    {branches.map((branch) => (
                                        <SelectItem key={branch.id} value={String(branch.id)}>
                                            {branch.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.branch_id} />
                        </div>
                    )}

                    <div className="space-y-1">
                        <Label htmlFor="dp-type">النوع</Label>
                        <Select value={data.type} onValueChange={(v) => setData('type', v as DeliveryProviderType)}>
                            <SelectTrigger id="dp-type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {types.map((type) => (
                                    <SelectItem key={type.value} value={type.value}>
                                        {type.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.type} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="dp-name">الاسم</Label>
                        <Input
                            id="dp-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="اسم السائق أو شركة التوصيل"
                            autoFocus
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="dp-phone">رقم الجوال</Label>
                        <Input
                            id="dp-phone"
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                            placeholder="05xxxxxxxx"
                            dir="ltr"
                            className="text-start"
                        />
                        <InputError message={errors.phone} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="dp-notes">ملاحظات</Label>
                        <textarea
                            id="dp-notes"
                            rows={2}
                            value={data.notes}
                            onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setData('notes', e.target.value)}
                            placeholder="اختياري"
                            className="flex min-h-[60px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        />
                        <InputError message={errors.notes} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="dp-is-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="dp-is-active" className="cursor-pointer">
                            نشط
                        </Label>
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="delivery-provider-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : isEdit ? 'حفظ التعديلات' : 'إضافة'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
