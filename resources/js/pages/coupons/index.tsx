import { destroy, toggleStatus } from '@/actions/App/Http/Controllers/CouponController';
import CouponFormModal from '@/components/coupons/coupon-form-modal';
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
import { formatDate } from '@/lib/utils';
import coupons from '@/routes/coupons';
import { type BreadcrumbItem } from '@/types';
import { type Coupon, type PaginatedCoupon } from '@/types/coupon';
import { router } from '@inertiajs/react';
import { Plus, Pencil, Trash2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'الكوبونات', href: '/coupons' },
];

interface Props {
    items: PaginatedCoupon;
    filters: {
        search?: string;
        status?: string;
    };
    branches?: { id: number; name: string }[];
}

export default function CouponsIndex({ items, filters, branches }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Coupon | null>(null);
    const [deleting, setDeleting] = useState<Coupon | null>(null);

    function openCreate() {
        setEditing(null);
        setFormOpen(true);
    }

    function openEdit(item: Coupon) {
        setEditing(item);
        setFormOpen(true);
    }

    function handleToggleStatus(item: Coupon) {
        router.patch(toggleStatus.url(item), {}, { preserveScroll: true });
    }

    function handleDelete() {
        if (!deleting) return;
        router.delete(destroy.url(deleting), {
            onFinish: () => setDeleting(null),
        });
    }

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
                coupons.index().url,
                { ...(value && { search: value }), ...(filterValues.status && { status: filterValues.status }) },
                { preserveState: true, replace: true },
            );
        }, 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        router.get(
            coupons.index().url,
            { ...(search && { search }), ...(next.status && { status: next.status }) },
            { preserveState: true, replace: true },
        );
    };

    const handleClearAll = () => {
        setSearch('');
        setFilterValues({ status: '' });
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get(coupons.index().url, {}, { preserveState: true, replace: true });
    };

    const columns = useMemo<ColumnDef<Coupon>[]>(
        () => [
            {
                key: 'code',
                header: 'الرمز',
                sortable: true,
                cell: (item) => (
                    <span className="font-mono font-semibold uppercase tracking-wider">{item.code}</span>
                ),
            },
            {
                key: 'discountType',
                header: 'نوع الخصم',
                cell: (item) => (
                    <Badge variant="secondary">{item.discountType.label}</Badge>
                ),
            },
            {
                key: 'discountValue',
                header: 'القيمة',
                cell: (item) => (
                    <span className="font-medium">
                        {item.discountType.value === 'percentage'
                            ? `${item.discountValue}%`
                            : `${item.discountValue} ر.س`}
                    </span>
                ),
            },
            {
                key: 'capacity',
                header: 'الاستخدام / الحد',
                cell: (item) => (
                    <span className="text-sm text-muted-foreground" dir="ltr">
                        {item.usedCount} / {item.capacity !== null ? item.capacity : '∞'}
                    </span>
                ),
            },
            {
                key: 'expiresAt',
                header: 'تاريخ الانتهاء',
                cell: (item) =>
                    item.expiresAt
                        ? formatDate(item.expiresAt)
                        : <span className="text-muted-foreground">—</span>,
            },
            {
                key: 'isActive',
                header: 'الحالة',
                cell: (item) => (
                    <button onClick={() => handleToggleStatus(item)} className="cursor-pointer">
                        {item.isActive ? (
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
                cell: (item) => (
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" onClick={() => openEdit(item)}>
                            <Pencil className="h-3.5 w-3.5" />
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={() => setDeleting(item)}
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
                    <h1 className="text-2xl font-bold">الكوبونات</h1>
                </div>

                <div className="mb-6">
                    <FilterBar
                        searchable
                        searchPlaceholder="بحث برمز الكوبون..."
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
                            <Button size="sm" onClick={openCreate}>
                                <Plus className="size-4" /> إضافة كوبون
                            </Button>
                        }
                    />
                </div>

                <DataTable
                    columns={columns}
                    data={items.data}
                    keyExtractor={(item) => item.id}
                />

                <TablePagination
                    currentPage={items.meta.current_page as number}
                    totalPages={items.meta.last_page as number}
                    totalItems={items.meta.total as number}
                    onPageChange={(page) => {
                        router.get(coupons.index().url, { page }, { preserveState: true, preserveScroll: true });
                    }}
                />
            </div>

            <Dialog open={!!deleting} onOpenChange={(open) => !open && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>
                            هل أنت متأكد من حذف الكوبون "{deleting?.code}"؟ لا يمكن التراجع عن هذا الإجراء.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeleting(null)}>
                            إلغاء
                        </Button>
                        <Button variant="destructive" onClick={handleDelete}>
                            حذف
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <CouponFormModal
                key={editing?.id ?? 'create'}
                open={formOpen}
                onOpenChange={setFormOpen}
                coupon={editing ?? undefined}
                branches={branches}
            />
        </AppLayout>
    );
}
