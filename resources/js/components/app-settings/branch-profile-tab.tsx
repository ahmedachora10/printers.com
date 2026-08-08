import { updateBranchProfile } from '@/actions/App/Http/Controllers/AppSettingController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
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
import { type Branch, type BranchProfileFormData } from '@/types/branch';
import { type City } from '@/types/city';
import { router, useForm } from '@inertiajs/react';

interface Props {
    branch: Branch;
    cities: City[];
}

export default function BranchProfileTab({ branch, cities }: Props) {
    const { data, setData, processing, errors, setError, clearErrors } = useForm<BranchProfileFormData>({
        name: branch.name ?? '',
        city_id: String(branch.cityId),
        phone: branch.phone ?? '',
        address: branch.address ?? '',
        business_type: branch.businessType ?? '',
        commercial_reg_no: branch.commercialRegNo ?? '',
        tax_number: branch.taxNumber ?? '',
        vat_rate_override: branch.vatRateOverride ?? 15,
        logo: null,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        // The logo makes this multipart, so the PUT is method-spoofed over POST.
        router.post(updateBranchProfile.url(), { ...data, _method: 'put' }, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                clearErrors();
                setData('logo', null);
            },
            onError: (errs) =>
                Object.entries(errs).forEach(([k, v]) => setError(k as keyof BranchProfileFormData, v)),
        });
    }

    return (
        <div className="rounded-lg border p-6">
            <h2 className="mb-1 text-lg font-semibold">بيانات الفرع</h2>
            <p className="text-muted-foreground mb-4 text-sm">
                تظهر هذه البيانات على الفواتير المطبوعة. لتغيير مالك الفرع أو حالة تفعيله راجع مدير النظام.
            </p>

            <form onSubmit={handleSubmit} className="space-y-4">
                {/* Logo */}
                <div className="space-y-1">
                    <Label>الشعار</Label>
                    <ImageUpload
                        value={data.logo}
                        onChange={(file) => setData('logo', file)}
                        currentUrl={branch.logoUrl || undefined}
                        error={errors.logo}
                    />
                </div>

                {/* Name + City */}
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-1">
                        <Label htmlFor="bp-name">
                            اسم الفرع <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id="bp-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="أدخل اسم الفرع"
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="bp-city">
                            المدينة <span className="text-destructive">*</span>
                        </Label>
                        <Select value={data.city_id} onValueChange={(val) => setData('city_id', val)}>
                            <SelectTrigger id="bp-city">
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
                        <InputError message={errors.city_id} />
                    </div>
                </div>

                {/* Phone + Business type */}
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-1">
                        <Label htmlFor="bp-phone">رقم الهاتف</Label>
                        <Input
                            id="bp-phone"
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                            placeholder="05XXXXXXXX"
                            dir="ltr"
                        />
                        <InputError message={errors.phone} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="bp-btype">نوع النشاط</Label>
                        <Input
                            id="bp-btype"
                            value={data.business_type}
                            onChange={(e) => setData('business_type', e.target.value)}
                            placeholder="طباعة، تصميم، ..."
                        />
                        <InputError message={errors.business_type} />
                    </div>
                </div>

                {/* Address */}
                <div className="space-y-1">
                    <Label htmlFor="bp-address">العنوان</Label>
                    <Input
                        id="bp-address"
                        value={data.address}
                        onChange={(e) => setData('address', e.target.value)}
                        placeholder="أدخل العنوان"
                    />
                    <InputError message={errors.address} />
                </div>

                {/* Commercial reg + Tax number */}
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-1">
                        <Label htmlFor="bp-crn">السجل التجاري</Label>
                        <Input
                            id="bp-crn"
                            value={data.commercial_reg_no}
                            onChange={(e) => setData('commercial_reg_no', e.target.value)}
                            placeholder="رقم السجل التجاري"
                            dir="ltr"
                        />
                        <InputError message={errors.commercial_reg_no} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="bp-tax">الرقم الضريبي</Label>
                        <Input
                            id="bp-tax"
                            value={data.tax_number}
                            onChange={(e) => setData('tax_number', e.target.value)}
                            placeholder="الرقم الضريبي"
                            dir="ltr"
                        />
                        <p className="text-muted-foreground text-xs">
                            يظهر على الفاتورة الضريبية ورمز QR؛ تأكّد من مطابقته لشهادة التسجيل الضريبي.
                        </p>
                        <InputError message={errors.tax_number} />
                    </div>
                </div>

                {/* VAT rate */}
                <div className="space-y-1">
                    <Label htmlFor="bp-vat">
                        نسبة ضريبة القيمة المضافة للفرع (%) <span className="text-destructive">*</span>
                    </Label>
                    <Input
                        id="bp-vat"
                        type="number"
                        step="0.01"
                        min="0"
                        max="100"
                        value={data.vat_rate_override}
                        onChange={(e) => setData('vat_rate_override', parseFloat(e.target.value) || 0)}
                        dir="ltr"
                    />
                    <p className="text-muted-foreground text-xs">
                        التغييرات لا تُطبَّق بأثر رجعي على الفواتير السابقة.
                    </p>
                    <InputError message={errors.vat_rate_override} />
                </div>

                <Button type="submit" disabled={processing}>
                    {processing ? 'جاري الحفظ...' : 'حفظ بيانات الفرع'}
                </Button>
            </form>
        </div>
    );
}
