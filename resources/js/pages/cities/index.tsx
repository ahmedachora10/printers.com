import { destroy, toggleStatus } from '@/actions/App/Http/Controllers/CityController';
import CityFormModal from '@/components/cities/city-form-modal';
import { DataTable, type ColumnDef } from '@/components/data-table';
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
import { router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
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

    const columns = useMemo<ColumnDef<City>[]>(
        () => [
            {
                key: 'name',
                header: 'اسم المدينة',
                sortable: true,
                cell: (city) => <span className="font-medium">{city.name}</span>,
            },
            {
                key: 'isActive',
                header: 'الحالة',
                cell: (city) => (
                    <Button onClick={() => handleToggleStatus(city)} className="cursor-pointer">
                        {city.isActive ? (
                            <Badge variant="outline" className="border-green-200 bg-green-50 text-green-700">
                                نشطة
                            </Badge>
                        ) : (
                            <Badge variant="outline" className="border-border bg-muted text-muted-foreground">
                                معطلة
                            </Badge>
                        )}
                    </Button>
                ),
            },
            {
                key: 'actions',
                header: '',
                headerClassName: 'w-24',
                cell: (city) => (
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" onClick={() => openEdit(city)}>
                            <Pencil className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={() => setDeletingCity(city)}
                        >
                            <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                    </div>
                ),
            },
        ],
        [],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">إدارة المدن</h1>
                </div>

                <DataTable
                    columns={columns}
                    data={cities.data}
                    keyExtractor={(city) => city.id}
                    searchable
                    searchPlaceholder="بحث في المدن..."
                    defaultPageSize={20}
                    toolbarEnd={
                        <Button onClick={openCreate} size="sm">
                            <Plus className="me-2 h-4 w-4" />
                            إضافة مدينة
                        </Button>
                    }
                />
            </div>

            {/* Delete confirmation dialog */}
            <Dialog open={!!deletingCity} onOpenChange={(open) => !open && setDeletingCity(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>
                            هل أنت متأكد من حذف مدينة "{deletingCity?.name}"؟ لا يمكن التراجع عن هذا الإجراء.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeletingCity(null)}>
                            إلغاء
                        </Button>
                        <Button variant="destructive" onClick={handleDelete}>
                            حذف
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <CityFormModal
                key={editingCity?.id ?? 'create'}
                open={formOpen}
                onOpenChange={setFormOpen}
                city={editingCity ?? undefined}
            />
        </AppLayout>
    );
}
