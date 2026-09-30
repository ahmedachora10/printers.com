import CardTypeController from '@/actions/App/Http/Controllers/CardTypeController';
import NetworkDeviceController from '@/actions/App/Http/Controllers/NetworkDeviceController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type PaymentMethod } from '@/types/payment-method';
import { router, useForm } from '@inertiajs/react';
import { CreditCard, Pencil, Plus, Star, Trash2 } from 'lucide-react';
import { useState } from 'react';

export interface NetworkDevice {
    id: number;
    branchId: number;
    branchName: string | null;
    paymentMethodId: number;
    paymentMethodName: string | null;
    name: string;
    number: string;
    isDefault: boolean;
}

export interface CardType {
    id: number;
    name: string;
}

interface Props {
    devices: NetworkDevice[];
    cardTypes: CardType[];
    paymentMethods: PaymentMethod[];
    branches: { id: number; name: string }[];
    isSuperAdmin: boolean;
    canManageDevices: boolean;
}

/** تاسك 146 — أجهزة الشبكة (لكل فرع، واحدٌ افتراضي) وأنواع البطاقات (قائمة عامة). */
export default function NetworkDevicesTab({ devices, cardTypes, paymentMethods, branches, isSuperAdmin, canManageDevices }: Props) {
    const [device, setDevice] = useState<NetworkDevice | 'new' | null>(null);
    const [cardType, setCardType] = useState<CardType | 'new' | null>(null);

    const remove = (url: string, label: string) => {
        if (confirm(`حذف «${label}»؟ تبقى المطابقات السابقة تشير إليه.`)) router.delete(url, { preserveScroll: true });
    };

    return (
        <div className="grid gap-6">
            <div className="rounded-lg border">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b p-4">
                    <div>
                        <h2 className="text-lg font-semibold">أجهزة الشبكة</h2>
                        <p className="text-muted-foreground text-sm">تُختار في مطابقة الحسابات؛ الجهاز الافتراضي يظهر تلقائياً عند فتح مطابقة جديدة.</p>
                    </div>
                    {canManageDevices && (
                        <Button size="sm" onClick={() => setDevice('new')}>
                            <Plus className="size-4" /> إضافة جهاز
                        </Button>
                    )}
                </div>
                {devices.length === 0 ? (
                    <p className="text-muted-foreground p-6 text-center text-sm">لا توجد أجهزة بعد.</p>
                ) : (
                    <ul className="divide-y">
                        {devices.map((d) => (
                            <li key={d.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                                <div className="flex min-w-0 flex-wrap items-center gap-2">
                                    <CreditCard className="text-muted-foreground size-4 shrink-0" />
                                    <span className="font-medium">{d.name}</span>
                                    <span className="text-muted-foreground text-sm tabular-nums" dir="ltr">
                                        {d.number}
                                    </span>
                                    {d.paymentMethodName && <Badge variant="outline">{d.paymentMethodName}</Badge>}
                                    {isSuperAdmin && d.branchName && (
                                        <Badge variant="outline" className="text-muted-foreground">
                                            {d.branchName}
                                        </Badge>
                                    )}
                                    {d.isDefault && (
                                        <Badge variant="outline" className="gap-1 border-amber-200 bg-amber-50 text-amber-700">
                                            <Star className="size-3" /> افتراضي
                                        </Badge>
                                    )}
                                </div>
                                {canManageDevices && (
                                    <div className="flex items-center gap-2">
                                        <Button variant="outline" size="sm" aria-label="تعديل" onClick={() => setDevice(d)}>
                                            <Pencil className="h-3.5 w-3.5" />
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            aria-label="حذف"
                                            className="text-destructive hover:text-destructive"
                                            onClick={() => remove(NetworkDeviceController.destroy.url(d.id), d.name)}
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            <div className="rounded-lg border">
                <div className="flex flex-wrap items-center justify-between gap-3 border-b p-4">
                    <div>
                        <h2 className="text-lg font-semibold">أنواع البطاقات</h2>
                        <p className="text-muted-foreground text-sm">تظهر لكل جهاز في المطابقة بحقل مبلغٍ لكل نوع. {!isSuperAdmin && 'يديرها المدير العام.'}</p>
                    </div>
                    {isSuperAdmin && (
                        <Button size="sm" onClick={() => setCardType('new')}>
                            <Plus className="size-4" /> إضافة نوع
                        </Button>
                    )}
                </div>
                <ul className="divide-y">
                    {cardTypes.map((c) => (
                        <li key={c.id} className="flex items-center justify-between gap-3 px-4 py-3">
                            <span className="font-medium">{c.name}</span>
                            {isSuperAdmin && (
                                <div className="flex items-center gap-2">
                                    <Button variant="outline" size="sm" aria-label="تعديل" onClick={() => setCardType(c)}>
                                        <Pencil className="h-3.5 w-3.5" />
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        aria-label="حذف"
                                        className="text-destructive hover:text-destructive"
                                        onClick={() => remove(CardTypeController.destroy.url(c.id), c.name)}
                                    >
                                        <Trash2 className="h-3.5 w-3.5" />
                                    </Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            </div>

            {device && (
                <DeviceDialog
                    device={device === 'new' ? null : device}
                    paymentMethods={paymentMethods}
                    branches={branches}
                    isSuperAdmin={isSuperAdmin}
                    onClose={() => setDevice(null)}
                />
            )}
            {cardType && <CardTypeDialog cardType={cardType === 'new' ? null : cardType} onClose={() => setCardType(null)} />}
        </div>
    );
}

function DeviceDialog({
    device,
    paymentMethods,
    branches,
    isSuperAdmin,
    onClose,
}: {
    device: NetworkDevice | null;
    paymentMethods: PaymentMethod[];
    branches: { id: number; name: string }[];
    isSuperAdmin: boolean;
    onClose: () => void;
}) {
    const form = useForm({
        branch_id: device?.branchId ?? (isSuperAdmin ? (branches[0]?.id ?? null) : null),
        payment_method_id: device?.paymentMethodId ?? null,
        name: device?.name ?? '',
        number: device?.number ?? '',
        is_default: device?.isDefault ?? false,
    });

    // طرق «شبكة» يراها فرع الجهاز: العامة + ما يخصّه.
    const methods = paymentMethods.filter((m) => m.isNetwork && (m.branchId === null || !isSuperAdmin || m.branchId === form.data.branch_id));

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (device) form.put(NetworkDeviceController.update.url(device.id), options);
        else form.post(NetworkDeviceController.store.url(), options);
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{device ? 'تعديل جهاز' : 'إضافة جهاز شبكة'}</DialogTitle>
                </DialogHeader>
                <form id="network-device-form" onSubmit={submit} className="space-y-4 py-2">
                    {isSuperAdmin && (
                        <div className="space-y-1">
                            <Label>الفرع</Label>
                            <Select
                                value={form.data.branch_id ? String(form.data.branch_id) : ''}
                                onValueChange={(v) => form.setData({ ...form.data, branch_id: Number(v), payment_method_id: null })}
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="اختر الفرع" />
                                </SelectTrigger>
                                <SelectContent>
                                    {branches.map((b) => (
                                        <SelectItem key={b.id} value={String(b.id)}>
                                            {b.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.branch_id} />
                        </div>
                    )}
                    <div className="space-y-1">
                        <Label htmlFor="nd-name">اسم الجهاز</Label>
                        <Input id="nd-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="مثال: جهاز الشبكة 1" autoFocus />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="nd-number">رقم الجهاز</Label>
                        <Input id="nd-number" dir="ltr" value={form.data.number} onChange={(e) => form.setData('number', e.target.value)} placeholder="123456" />
                        <InputError message={form.errors.number} />
                    </div>
                    <div className="space-y-1">
                        <Label>طريقة الدفع (شبكة)</Label>
                        <Select value={form.data.payment_method_id ? String(form.data.payment_method_id) : ''} onValueChange={(v) => form.setData('payment_method_id', Number(v))}>
                            <SelectTrigger>
                                <SelectValue placeholder="اختر الطريقة" />
                            </SelectTrigger>
                            <SelectContent>
                                {methods.map((m) => (
                                    <SelectItem key={m.id} value={String(m.id)}>
                                        {m.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {methods.length === 0 && <p className="text-muted-foreground text-xs">لا توجد طرق دفع معلَّمة «شبكة» — فعّلها من تبويب طرق الدفع.</p>}
                        <InputError message={form.errors.payment_method_id} />
                    </div>
                    <div className="flex items-center gap-2">
                        <Checkbox id="nd-default" checked={form.data.is_default} onCheckedChange={(c) => form.setData('is_default', c === true)} />
                        <Label htmlFor="nd-default" className="cursor-pointer">
                            الجهاز الافتراضي للفرع
                        </Label>
                    </div>
                </form>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose} disabled={form.processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="network-device-form" disabled={form.processing}>
                        {form.processing ? 'جاري الحفظ...' : 'حفظ'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function CardTypeDialog({ cardType, onClose }: { cardType: CardType | null; onClose: () => void }) {
    const form = useForm({ name: cardType?.name ?? '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (cardType) form.put(CardTypeController.update.url(cardType.id), options);
        else form.post(CardTypeController.store.url(), options);
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-sm">
                <DialogHeader>
                    <DialogTitle>{cardType ? 'تعديل نوع البطاقة' : 'إضافة نوع بطاقة'}</DialogTitle>
                </DialogHeader>
                <form id="card-type-form" onSubmit={submit} className="space-y-4 py-2">
                    <div className="space-y-1">
                        <Label htmlFor="ct-name">الاسم</Label>
                        <Input id="ct-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="مثال: أمريكان إكسبريس" autoFocus />
                        <InputError message={form.errors.name} />
                    </div>
                </form>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose} disabled={form.processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="card-type-form" disabled={form.processing}>
                        {form.processing ? 'جاري الحفظ...' : 'حفظ'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
