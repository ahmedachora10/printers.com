import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type City } from '@/types/city';
import { Link, useForm } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'المدن', href: '/cities' },
    { title: 'تعديل مدينة', href: '#' },
];

interface Props {
    city: City;
}

export default function CityEdit({ city }: Props) {
    const { data, setData, put, processing, errors } = useForm({
        name: city.name,
        is_active: city.isActive,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        put(route('cities.update', city.id));
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <h1 className="mb-6 text-2xl font-bold">تعديل مدينة</h1>

                <div className="max-w-md rounded-lg border bg-white p-6 shadow-sm">
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="space-y-1">
                            <Label htmlFor="name">اسم المدينة</Label>
                            <Input
                                id="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="أدخل اسم المدينة"
                                autoFocus
                            />
                            {errors.name && (
                                <p className="text-sm text-red-600">{errors.name}</p>
                            )}
                        </div>

                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="is_active"
                                checked={data.is_active}
                                onCheckedChange={(checked) =>
                                    setData('is_active', checked === true)
                                }
                            />
                            <Label htmlFor="is_active" className="cursor-pointer">
                                نشطة
                            </Label>
                        </div>

                        <div className="flex gap-3 pt-2">
                            <Button type="submit" disabled={processing}>
                                {processing ? 'جاري الحفظ...' : 'حفظ التعديلات'}
                            </Button>
                            <Link href={route('cities.index')}>
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
