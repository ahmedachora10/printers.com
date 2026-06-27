import { destroy, index, toggleStatus } from '@/actions/App/Http/Controllers/CityController';
import CityFormModal from '@/components/cities/city-form-modal';
import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { FilterBar } from '@/components/filter-bar';
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
import { Download, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'المدن', href: '/cities' },
];

interface Props {
    cities: PaginatedCity;
    filters: {
        search?: string;
        status?: string;
    };
}

export default function CitiesIndex({ cities, filters }: Props) {
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
                    <button onClick={() => handleToggleStatus(city)} className="cursor-pointer">
                        {city.isActive ? (
                            <Badge variant="outline" className="gap-1.5 border-green-200 bg-green-50 text-green-700">
                                <span className="inline-block size-1.5 rounded-full bg-green-500" />
                                نشط
                            </Badge>
                        ) : (
                            <Badge variant="outline" className="gap-1.5 border-border bg-muted/60 text-muted-foreground">
                                <span className="inline-block size-1.5 rounded-full bg-muted-foreground/50" />
                                غير نشط
                            </Badge>
                        )}
                    </button>
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


    const [search, setSearch] = useState(filters.search ?? '');
    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        status: filters.status ?? '',
    });
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            router.get(
                index.url(),
                { ...(value && { search: value }), ...(filterValues.status && { status: filterValues.status }) },
                { preserveState: true, replace: true },
            );
        }, 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        router.get(
            index.url(),
            { ...(search && { search }), ...(next.status && { status: next.status }) },
            { preserveState: true, replace: true },
        );
    };

    const handleClearAll = () => {
        setSearch('');
        setFilterValues({ status: '' });
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get(index.url(), {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">إدارة المدن</h1>
                </div>

                <div className="mb-6">
                    <FilterBar
                        searchable
                        searchPlaceholder="بحث في المدن..."
                        searchValue={search}
                        onSearchChange={handleSearchChange}
                        filters={[
                            {
                                key: 'status',
                                placeholder: 'الحالة',
                                options: [
                                    { value: '1', label: 'نشط' },
                                    { value: '0', label: 'غير نشط' },
                                ],
                            },
                        ]}
                        filterValues={filterValues}
                        onFilterChange={handleFilterChange}
                        onClearAll={handleClearAll}
                        actions={
                            <>
                                <Button variant="outline" size="sm"><Download className="size-4" /> تصدير</Button>
                                <Button size="sm" onClick={openCreate}><Plus className="size-4" /> إضافة مدينة</Button>
                            </>
                        }
                    />
                </div>

                <DataTable
                    columns={columns}
                    data={cities.data}
                    keyExtractor={(city) => city.id}
                />

                <TablePagination
                    currentPage={cities.meta.current_page as number}
                    totalPages={cities.meta.last_page as number}
                    totalItems={cities.meta.total as number}
                    onPageChange={(page) => {
                        router.reload({ data: { page } });
                    }}
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
