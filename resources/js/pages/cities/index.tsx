import { destroy, toggleStatus } from '@/actions/App/Http/Controllers/CityController';
import CityFormModal from '@/components/cities/city-form-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type City, type PaginatedCity } from '@/types/city';
import { Link, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'المدن', href: '/cities' },
];

interface Props {
    cities: PaginatedCity;
}

export default function CitiesIndex({ cities }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [editingCity, setEditingCity] = useState<City | null>(null);
    const [deletingCity, setDeletingCity] = useState<City | null>(null);

    function openCreate() {
        setEditingCity(null);
        setFormOpen(true);
    }

    function openEdit(city: City) {
        setEditingCity(city);
        setFormOpen(true);
    }

    function handleToggleStatus(city: City) {
        router.patch(toggleStatus.url(city), {}, { preserveScroll: true });
    }

    function handleDelete() {
        if (!deletingCity) return;
        router.delete(destroy.url(deletingCity), {
            onFinish: () => setDeletingCity(null),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">إدارة المدن</h1>
                    <Button onClick={openCreate}>
                        <Plus className="me-2 h-4 w-4" />
                        إضافة مدينة
                    </Button>
                </div>

                <div className="overflow-hidden rounded-lg border bg-white shadow-sm">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-gray-50 text-right text-xs font-semibold uppercase text-gray-500">
                            <tr>
                                <th className="px-4 py-3">الاسم</th>
                                <th className="px-4 py-3">الحالة</th>
                                <th className="px-4 py-3">الإجراءات</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {cities.data.length === 0 && (
                                <tr>
                                    <td colSpan={3} className="px-4 py-8 text-center text-gray-400">
                                        لا توجد مدن مسجلة
                                    </td>
                                </tr>
                            )}
                            {cities.data.map((city) => (
                                <tr key={city.id} className="hover:bg-gray-50">
                                    <td className="px-4 py-3 font-medium">{city.name}</td>
                                    <td className="px-4 py-3">
                                        <button
                                            onClick={() => handleToggleStatus(city)}
                                            className="cursor-pointer"
                                        >
                                            {city.isActive ? (
                                                <Badge variant="default">نشطة</Badge>
                                            ) : (
                                                <Badge variant="secondary">معطلة</Badge>
                                            )}
                                        </button>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex gap-2">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => openEdit(city)}
                                            >
                                                <Pencil className="h-3.5 w-3.5" />
                                            </Button>

                                            <Dialog
                                                open={deletingCity?.id === city.id}
                                                onOpenChange={(open) =>
                                                    setDeletingCity(open ? city : null)
                                                }
                                            >
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="text-red-600 hover:text-red-700"
                                                    onClick={() => setDeletingCity(city)}
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                </Button>
                                                <DialogContent>
                                                    <DialogHeader>
                                                        <DialogTitle>تأكيد الحذف</DialogTitle>
                                                        <DialogDescription>
                                                            هل أنت متأكد من حذف مدينة "{city.name}"؟ لا يمكن التراجع عن هذا الإجراء.
                                                        </DialogDescription>
                                                    </DialogHeader>
                                                    <DialogFooter>
                                                        <Button
                                                            variant="outline"
                                                            onClick={() => setDeletingCity(null)}
                                                        >
                                                            إلغاء
                                                        </Button>
                                                        <Button variant="destructive" onClick={handleDelete}>
                                                            حذف
                                                        </Button>
                                                    </DialogFooter>
                                                </DialogContent>
                                            </Dialog>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {cities.data.length > 0 && (
                    <div className="mt-4 flex justify-center gap-1">
                        {Object.entries(cities.links).map(([key, url]) =>
                            url ? (
                                <Link
                                    key={key}
                                    href={url}
                                    className="rounded border px-3 py-1 text-sm hover:bg-gray-100"
                                >
                                    {key}
                                </Link>
                            ) : null,
                        )}
                    </div>
                )}
            </div>

            <CityFormModal
                key={editingCity?.id ?? 'create'}
                open={formOpen}
                onOpenChange={setFormOpen}
                city={editingCity ?? undefined}
            />
        </AppLayout>
    );
}
