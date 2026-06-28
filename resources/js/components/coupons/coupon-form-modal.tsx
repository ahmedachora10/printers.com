import { store, update } from '@/actions/App/Http/Controllers/CouponController';
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
import { type Coupon } from '@/types/coupon';
import { useForm } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useEffect } from 'react';
import InputError from '../input-error';

function generateCouponCode(): string {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    return Array.from({ length: 8 }, () => chars[Math.floor(Math.random() * chars.length)]).join('');
}

interface Branch {
    id: number;
    name: string;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    coupon?: Coupon;
    branches?: Branch[];
}

export default function CouponFormModal({ open, onOpenChange, coupon, branches }: Props) {
    const isEdit = !!coupon;
    const isSuperAdmin = Array.isArray(branches);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        branch_id: coupon?.branchId?.toString() ?? (branches?.[0]?.id?.toString() ?? ''),
        code: coupon?.code ?? '',
        discount_type: coupon?.discountType.value ?? 'percentage',
        discount_value: coupon?.discountValue?.toString() ?? '',
        capacity: coupon?.capacity?.toString() ?? '',
        expires_at: coupon?.expiresAt ? coupon.expiresAt.slice(0, 10) : '',
        is_active: coupon?.isActive ?? true,
    });

    useEffect(() => {
        if (coupon) {
            setData({
                branch_id: coupon.branchId?.toString() ?? (branches?.[0]?.id?.toString() ?? ''),
                code: coupon.code ?? '',
                discount_type: coupon.discountType.value ?? 'percentage',
                discount_value: coupon.discountValue?.toString() ?? '',
                capacity: coupon.capacity?.toString() ?? '',
                expires_at: coupon.expiresAt ? coupon.expiresAt.slice(0, 10) : '',
                is_active: coupon.isActive ?? true,
            });
        } else {
            reset();
        }
    }, [coupon, open]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        if (isEdit) {
            put(update.url(coupon), {
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
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'تعديل كوبون' : 'إضافة كوبون'}</DialogTitle>
                </DialogHeader>

                <form id="coupon-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    {isSuperAdmin && (
                        <div className="space-y-1">
                            <Label htmlFor="coupon-branch">الفرع</Label>
                            <Select
                                value={data.branch_id}
                                onValueChange={(val) => setData('branch_id', val)}
                                disabled={isEdit}
                            >
                                <SelectTrigger id="coupon-branch">
                                    <SelectValue placeholder="اختر الفرع" />
                                </SelectTrigger>
                                <SelectContent>
                                    {branches!.map((b) => (
                                        <SelectItem key={b.id} value={b.id.toString()}>
                                            {b.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.branch_id} />
                        </div>
                    )}

                    <div className="space-y-1">
                        <Label htmlFor="coupon-code">الرمز</Label>
                        <div className="flex gap-2">
                            <Input
                                id="coupon-code"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                placeholder="مثال: SUMMER25"
                                autoFocus
                                dir="ltr"
                                className="flex-1 font-mono tracking-widest uppercase"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="icon"
                                title="توليد رمز عشوائي"
                                onClick={() => setData('code', generateCouponCode())}
                            >
                                <RefreshCw className="h-4 w-4" />
                            </Button>
                        </div>
                        <InputError message={errors.code} />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="coupon-discount-type">نوع الخصم</Label>
                            <Select
                                value={data.discount_type}
                                onValueChange={(val) => setData('discount_type', val as 'percentage' | 'fixed')}
                            >
                                <SelectTrigger id="coupon-discount-type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="percentage">نسبة مئوية %</SelectItem>
                                    <SelectItem value="fixed">مبلغ ثابت ر.س</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={errors.discount_type} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="coupon-discount-value">
                                قيمة الخصم {data.discount_type === 'percentage' ? '(%)' : '(ر.س)'}
                            </Label>
                            <Input
                                id="coupon-discount-value"
                                type="number"
                                min="0"
                                step="0.01"
                                value={data.discount_value}
                                onChange={(e) => setData('discount_value', e.target.value)}
                                placeholder="0"
                                dir="ltr"
                            />
                            <InputError message={errors.discount_value} />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="coupon-capacity">الحد الأقصى للاستخدام</Label>
                            <Input
                                id="coupon-capacity"
                                type="number"
                                min="1"
                                value={data.capacity}
                                onChange={(e) => setData('capacity', e.target.value)}
                                placeholder="غير محدود"
                                dir="ltr"
                            />
                            <InputError message={errors.capacity} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="coupon-expires-at">تاريخ الانتهاء</Label>
                            <Input
                                id="coupon-expires-at"
                                type="date"
                                value={data.expires_at}
                                onChange={(e) => setData('expires_at', e.target.value)}
                                dir="ltr"
                            />
                            <InputError message={errors.expires_at} />
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="coupon-is-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="coupon-is-active" className="cursor-pointer">
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
                    <Button type="submit" form="coupon-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : isEdit ? 'حفظ التعديلات' : 'إضافة'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
