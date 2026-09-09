import { store, update } from '@/actions/App/Http/Controllers/DeliveryZoneController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { DeliveryZone, DeliveryZoneType, EnumOption, ShippingBranchOption } from '@/types/shipping';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    zone?: DeliveryZone;
    types: EnumOption[];
    branches: ShippingBranchOption[];
    isSuperAdmin: boolean;
    defaultBranchId?: number | null;
}

export default function DeliveryZoneFormModal({
    open,
    onOpenChange,
    zone,
    types,
    branches,
    isSuperAdmin,
    defaultBranchId,
}: Props) {
    const isEdit = !!zone;

    const { data, setData, post, put, processing, errors, reset } = useForm({
        branch_id: zone?.branchId ?? defaultBranchId ?? ('' as number | ''),
        type: zone?.type.value ?? 'area',
        name: zone?.name ?? '',
        from_km: zone?.fromKm != null ? String(zone.fromKm) : '',
        to_km: zone?.toKm != null ? String(zone.toKm) : '',
        price: zone?.price != null ? String(zone.price) : '',
        sort_order: zone?.sortOrder != null ? String(zone.sortOrder) : '0',
        is_active: zone?.isActive ?? true,
    });

    useEffect(() => {
        if (zone) {
            setData({
                branch_id: zone.branchId,
                type: zone.type.value,
                name: zone.name ?? '',
                from_km: zone.fromKm != null ? String(zone.fromKm) : '',
                to_km: zone.toKm != null ? String(zone.toKm) : '',
                price: String(zone.price),
                sort_order: String(zone.sortOrder ?? 0),
                is_active: zone.isActive,
            });
        } else {
            reset();
            setData('branch_id', defaultBranchId ?? ('' as number | ''));
        }
    }, [zone, open]);

    const isDistance = data.type === 'distance';

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
            put(update.url(zone), options);
        } else {
            post(store.url(), options);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'تعديل الشريحة' : 'إضافة شريحة سعر'}</DialogTitle>
                </DialogHeader>

                <form id="delivery-zone-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    {isSuperAdmin && !isEdit && (
                        <div className="space-y-1">
                            <Label htmlFor="dz-branch">الفرع</Label>
                            <Select
                                value={data.branch_id === '' ? undefined : String(data.branch_id)}
                                onValueChange={(v) => setData('branch_id', Number(v))}
                            >
                                <SelectTrigger id="dz-branch">
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
                        <Label htmlFor="dz-type">النوع</Label>
                        <Select value={data.type} onValueChange={(v) => setData('type', v as DeliveryZoneType)}>
                            <SelectTrigger id="dz-type">
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
                        <p className="text-xs text-muted-foreground">
                            {isDistance
                                ? 'شريحة مسافة: تُختار بالاسم، أو تلقائياً حين يكتب الموظف المسافة.'
                                : 'حي: يُختار بالاسم، ولا تُقاس عليه مسافة.'}
                        </p>
                        <InputError message={errors.type} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="dz-name">{isDistance ? 'اسم الشريحة' : 'اسم الحي'}</Label>
                        <Input
                            id="dz-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder={isDistance ? 'من 0 إلى 5 كم' : 'حي النرجس'}
                            autoFocus
                        />
                        <InputError message={errors.name} />
                    </div>

                    {/* الحدود لشريحة المسافة وحدها — صفّ الحي لا مدى له،
                        والخادم يُفرغهما على أي حال قبل الحفظ. */}
                    {isDistance && (
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label htmlFor="dz-from">من (كم)</Label>
                                <Input
                                    id="dz-from"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={data.from_km}
                                    onChange={(e) => setData('from_km', e.target.value)}
                                    placeholder="0"
                                />
                                <InputError message={errors.from_km} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="dz-to">إلى (كم)</Label>
                                <Input
                                    id="dz-to"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    value={data.to_km}
                                    onChange={(e) => setData('to_km', e.target.value)}
                                    placeholder="اتركه فارغاً للمفتوحة"
                                />
                                <InputError message={errors.to_km} />
                            </div>
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-3">
                        <div className="space-y-1">
                            <Label htmlFor="dz-price">السعر (ر.س)</Label>
                            <Input
                                id="dz-price"
                                type="number"
                                step="0.01"
                                min="0"
                                value={data.price}
                                onChange={(e) => setData('price', e.target.value)}
                                placeholder="0.00"
                            />
                            <p className="text-xs text-muted-foreground">شامل الضريبة</p>
                            <InputError message={errors.price} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="dz-sort">الترتيب</Label>
                            <Input
                                id="dz-sort"
                                type="number"
                                min="0"
                                value={data.sort_order}
                                onChange={(e) => setData('sort_order', e.target.value)}
                            />
                            <InputError message={errors.sort_order} />
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="dz-is-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="dz-is-active" className="cursor-pointer">
                            نشطة
                        </Label>
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="delivery-zone-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : isEdit ? 'حفظ التعديلات' : 'إضافة'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
