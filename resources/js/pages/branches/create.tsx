import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type City } from '@/types/city';
import { Link, useForm } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'الفروع', href: '/branches' },
    { title: 'إضافة فرع', href: '/branches/create' },
];

interface Props {
    cities: City[];
}

export default function BranchCreate({ cities }: Props) {
    const { data, setData, post, processing, errors } = useForm<{
        name: string;
        city_id: string;
        phone: string;
        address: string;
        business_type: string;
        commercial_reg_no: string;
        tax_number: string;
        vat_rate_override: number;
        is_active: boolean;
        logo: File | null;
    }>({
        name: '',
        city_id: '',
        phone: '',
        address: '',
        business_type: '',
        commercial_reg_no: '',
        tax_number: '',
        vat_rate_override: 15,
        is_active: true,
        logo: null,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post(route('branches.store'), { forceFormData: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <h1 className="mb-6 text-2xl font-bold">إضافة فرع</h1>

                <div className="max-w-2xl rounded-lg border bg-white p-6 shadow-sm">
                    <form onSubmit={handleSubmit} className="space-y-5">
                        {/* Name */}
                        <div className="space-y-1">
                            <Label htmlFor="name">اسم الفرع <span className="text-destructive">*</span></Label>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="أدخل اسم الفرع"
                                autoFocus
                            />
                            {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                        </div>

                        {/* City */}
                        <div className="space-y-1">
                            <Label htmlFor="city_id">المدينة <span className="text-destructive">*</span></Label>
                            <Select value={data.city_id} onValueChange={(val) => setData('city_id', val)}>
                                <SelectTrigger id="city_id">
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
                                <Label htmlFor="phone">رقم الهاتف</Label>
                                <Input
                                    id="phone"
                                    value={data.phone}
                                    onChange={(e) => setData('phone', e.target.value)}
                                    placeholder="05XXXXXXXX"
                                    dir="ltr"
                                />
                                {errors.phone && <p className="text-sm text-destructive">{errors.phone}</p>}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="business_type">نوع النشاط</Label>
                                <Input
                                    id="business_type"
                                    value={data.business_type}
                                    onChange={(e) => setData('business_type', e.target.value)}
                                    placeholder="طباعة، تصميم، ..."
                                />
                                {errors.business_type && <p className="text-sm text-destructive">{errors.business_type}</p>}
                            </div>
                        </div>

                        {/* Address */}
                        <div className="space-y-1">
                            <Label htmlFor="address">العنوان</Label>
                            <Input
                                id="address"
                                value={data.address}
                                onChange={(e) => setData('address', e.target.value)}
                                placeholder="أدخل العنوان"
                            />
                            {errors.address && <p className="text-sm text-destructive">{errors.address}</p>}
                        </div>

                        {/* Commercial Reg No + Tax Number */}
                        <div className="grid grid-cols-2 gap-4">
                            <div className="space-y-1">
                                <Label htmlFor="commercial_reg_no">السجل التجاري</Label>
                                <Input
                                    id="commercial_reg_no"
                                    value={data.commercial_reg_no}
                                    onChange={(e) => setData('commercial_reg_no', e.target.value)}
                                    placeholder="رقم السجل التجاري"
                                    dir="ltr"
                                />
                                {errors.commercial_reg_no && (
                                    <p className="text-sm text-destructive">{errors.commercial_reg_no}</p>
                                )}
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="tax_number">الرقم الضريبي</Label>
                                <Input
                                    id="tax_number"
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
                            <Label htmlFor="vat_rate_override">نسبة الضريبة (%) <span className="text-destructive">*</span></Label>
                            <Input
                                id="vat_rate_override"
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                value={data.vat_rate_override}
                                onChange={(e) => setData('vat_rate_override', parseFloat(e.target.value) || 0)}
                                dir="ltr"
                            />
                            {errors.vat_rate_override && (
                                <p className="text-sm text-destructive">{errors.vat_rate_override}</p>
                            )}
                        </div>

                        {/* Logo */}
                        <div className="space-y-1">
                            <Label htmlFor="logo">الشعار</Label>
                            <Input
                                id="logo"
                                type="file"
                                accept="image/*"
                                onChange={(e) => setData('logo', e.target.files?.[0] ?? null)}
                            />
                            {errors.logo && <p className="text-sm text-destructive">{errors.logo}</p>}
                        </div>

                        {/* Is Active */}
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="is_active"
                                checked={data.is_active}
                                onCheckedChange={(checked) => setData('is_active', checked === true)}
                            />
                            <Label htmlFor="is_active" className="cursor-pointer">
                                نشط
                            </Label>
                        </div>

                        <div className="flex gap-3 pt-2">
                            <Button type="submit" disabled={processing}>
                                {processing ? 'جاري الحفظ...' : 'حفظ'}
                            </Button>
                            <Link href={route('branches.index')}>
                                <Button type="button" variant="outline">
                                    إلغاء
                                </Button>
                            </Link>
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
