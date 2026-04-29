import { store, update } from '@/actions/App/Http/Controllers/BranchController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { ImageUpload } from '@/components/ui/image-upload';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { type Branch, type BranchAdmin } from '@/types/branch';
import { type City } from '@/types/city';
import { router, useForm } from '@inertiajs/react';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    branch?: Branch;
    cities: City[];
    branchAdmins: BranchAdmin[];
}

export default function BranchFormModal({ open, onOpenChange, branch, cities, branchAdmins }: Props) {
    const isEdit = !!branch;

    const { data, setData, post, put, processing, errors, reset } = useForm<{
        name: string;
        city_id: string;
        phone: string;
        address: string;
        business_type: string;
        commercial_reg_no: string;
        tax_number: string;
        owner_id: string;
        vat_rate_override: number;
        is_active: boolean;
        logo: File | null;
    }>({
        name: branch?.name ?? '',
        city_id: branch ? String(branch.cityId) : '',
        phone: branch?.phone ?? '',
        address: branch?.address ?? '',
        business_type: branch?.businessType ?? '',
        commercial_reg_no: branch?.commercialRegNo ?? '',
        tax_number: branch?.taxNumber ?? '',
        owner_id: branch?.ownerId ? String(branch.ownerId) : '',
        vat_rate_override: branch?.vatRateOverride ?? 15,
        is_active: branch?.isActive ?? true,
        logo: null,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        const options = {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { onOpenChange(false); reset(); },
        };

        if (isEdit) {
            router.post(update.url(branch), { ...data, _method: 'put' }, options);
        } else {
            post(store.url(), options);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'تعديل فرع' : 'إضافة فرع'}</DialogTitle>
                </DialogHeader>

                <form
                    id="branch-form"
                    onSubmit={handleSubmit}
                    className="flex-1 space-y-4 overflow-y-auto py-2 pe-1"
                >

                    {/* Logo */}
                    <div className="space-y-1">
                        <Label>الشعار</Label>
                        <ImageUpload
                            value={data.logo}
                            onChange={(file) => setData('logo', file)}
                            currentUrl={isEdit ? (branch.logoUrl ?? undefined) : undefined}
                            error={errors.logo}
                        />
                    </div>

                    {/* Name */}
                    <div className="space-y-1">
                        <Label htmlFor="b-name">اسم الفرع <span className="text-destructive">*</span></Label>
                        <Input
                            id="b-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="أدخل اسم الفرع"
                            autoFocus
                        />
                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                    </div>

                    {/* City */}
                    <div className="space-y-1">
                        <Label htmlFor="b-city">المدينة <span className="text-destructive">*</span></Label>
                        <Select value={data.city_id} onValueChange={(val) => setData('city_id', val)}>
                            <SelectTrigger id="b-city">
                                <SelectValue placeholder="اختر المدينة" />
                            </SelectTrigger>
                            <SelectContent>
                                {cities.map((city) => (
                                    <SelectItem key={city.id} value={String(city.id)}>
                                        {city.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.city_id && <p className="text-sm text-destructive">{errors.city_id}</p>}
                    </div>

                    {/* Phone + Business Type */}
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="b-phone">رقم الهاتف</Label>
                            <Input
                                id="b-phone"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                                placeholder="05XXXXXXXX"
                                dir="ltr"
                            />
                            {errors.phone && <p className="text-sm text-destructive">{errors.phone}</p>}
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="b-btype">نوع النشاط</Label>
                            <Input
                                id="b-btype"
                                value={data.business_type}
                                onChange={(e) => setData('business_type', e.target.value)}
                                placeholder="طباعة، تصميم، ..."
                            />
                            {errors.business_type && <p className="text-sm text-destructive">{errors.business_type}</p>}
                        </div>
                    </div>

                    {/* Address */}
                    <div className="space-y-1">
                        <Label htmlFor="b-address">العنوان</Label>
                        <Input
                            id="b-address"
                            value={data.address}
                            onChange={(e) => setData('address', e.target.value)}
                            placeholder="أدخل العنوان"
                        />
                        {errors.address && <p className="text-sm text-destructive">{errors.address}</p>}
                    </div>

                    {/* Commercial Reg No + Tax Number */}
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="b-crn">السجل التجاري</Label>
                            <Input
                                id="b-crn"
                                value={data.commercial_reg_no}
                                onChange={(e) => setData('commercial_reg_no', e.target.value)}
                                placeholder="رقم السجل التجاري"
                                dir="ltr"
                            />
                            {errors.commercial_reg_no && <p className="text-sm text-destructive">{errors.commercial_reg_no}</p>}
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="b-tax">الرقم الضريبي</Label>
                            <Input
                                id="b-tax"
                                value={data.tax_number}
                                onChange={(e) => setData('tax_number', e.target.value)}
                                placeholder="الرقم الضريبي"
                                dir="ltr"
                            />
                            {errors.tax_number && <p className="text-sm text-destructive">{errors.tax_number}</p>}
                        </div>
                    </div>

                    {/* VAT Rate */}
                    <div className="space-y-1">
                        <Label htmlFor="b-vat">نسبة الضريبة (%) <span className="text-destructive">*</span></Label>
                        <Input
                            id="b-vat"
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            value={data.vat_rate_override}
                            onChange={(e) => setData('vat_rate_override', parseFloat(e.target.value) || 0)}
                            dir="ltr"
                        />
                        {errors.vat_rate_override && <p className="text-sm text-destructive">{errors.vat_rate_override}</p>}
                    </div>

                    {/* Owner */}
                    <div className="space-y-1">
                        <Label htmlFor="b-owner">مالك الفرع</Label>
                        <Select
                            value={data.owner_id}
                            onValueChange={(val) => setData('owner_id', val === '_none' ? '' : val)}
                        >
                            <SelectTrigger id="b-owner">
                                <SelectValue placeholder="بدون مالك" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="_none">بدون مالك</SelectItem>
                                {branchAdmins.map((admin) => (
                                    <SelectItem key={admin.id} value={String(admin.id)}>
                                        {admin.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.owner_id && <p className="text-sm text-destructive">{errors.owner_id}</p>}
                    </div>

                    {/* Is Active */}
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="b-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="b-active" className="cursor-pointer">نشط</Label>
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
                    <Button type="submit" form="branch-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : isEdit ? 'حفظ التعديلات' : 'إضافة'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
