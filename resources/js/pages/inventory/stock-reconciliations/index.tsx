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
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import {
    reconciliationBadgeClass,
    type PaginatedStockReconciliation,
    type StockReconciliation,
} from '@/types/stock-reconciliation';
import { Link, router, useForm } from '@inertiajs/react';
import { Eye, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';
import inventory from '@/routes/inventory';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'المستودع', href: inventory.products.index().url },
    { title: 'جرد المخزون', href: inventory.stockReconciliations.index().url },
];

interface BranchOption {
    id: number;
    name: string;
}

interface Props {
    items: PaginatedStockReconciliation;
    branches: BranchOption[];
    canManage: boolean;
    filters: {
        status?: string;
    };
}

const STATUS_OPTIONS = [
    { value: 'in_progress', label: 'قيد الجرد' },
    { value: 'completed', label: 'مكتمل' },
];

export default function StockReconciliationsIndex({ items, branches, canManage, filters }: Props) {
    const [startOpen, setStartOpen] = useState(false);

    const form = useForm<{ branch_id: string; notes: string }>({ branch_id: '', notes: '' });

    const handleStart = () => {
        form.transform((data) => ({
            ...(data.branch_id && { branch_id: Number(data.branch_id) }),
            ...(data.notes && { notes: data.notes }),
        }));
        form.post(inventory.stockReconciliations.store().url, {
            onSuccess: () => {
                setStartOpen(false);
                form.reset();
            },
        });
    };

    const columns = useMemo<ColumnDef<StockReconciliation>[]>(
        () => [
            {
                key: 'id',
                header: 'الجرد',
                cell: (item) => (
                    <Link
                        href={inventory.stockReconciliations.show(item.id).url}
                        className="font-mono text-xs tracking-wider text-primary hover:underline"
                    >
                        #{item.id}
                    </Link>
                ),
            },
            {
                key: 'branchName',
                header: 'الفرع',
                cell: (item) => item.branchName ?? '—',
            },
            {
                key: 'status',
                header: 'الحالة',
                cell: (item) => (
                    <Badge variant="outline" className={reconciliationBadgeClass(item)}>
                        {item.statusLabel}
                    </Badge>
                ),
            },
            {
                key: 'linesCount',
                header: 'عدد المنتجات',
                cell: (item) => <span className="tabular-nums">{item.linesCount ?? '—'}</span>,
            },
            {
                key: 'initiatedByName',
                header: 'بدأه',
                cell: (item) => item.initiatedByName ?? '—',
            },
            {
                key: 'createdAt',
                header: 'تاريخ البدء',
                cell: (item) => <span dir="ltr">{item.createdAt ?? '—'}</span>,
            },
            {
                key: 'completedAt',
                header: 'تاريخ الاعتماد',
                cell: (item) => <span dir="ltr">{item.completedAt ?? '—'}</span>,
            },
            {
                key: 'actions',
                header: '',
                headerClassName: 'w-16',
                cell: (item) => (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={inventory.stockReconciliations.show(item.id).url}>
                            <Eye className="h-3.5 w-3.5" />
                        </Link>
                    </Button>
                ),
            },
        ],
        [],
    );

    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        status: filters.status ?? '',
    });

    const handleFilterChange = (key: string, val: string) => {
        setFilterValues({ ...filterValues, [key]: val });
        router.get(
            inventory.stockReconciliations.index().url,
            val ? { status: val } : {},
            { preserveState: true, replace: true },
        );
    };

    const handleClearAll = () => {
        setFilterValues({ status: '' });
        router.get(inventory.stockReconciliations.index().url, {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">جرد المخزون</h1>
                </div>

                <div className="mb-6">
                    <FilterBar
                        filters={[{ key: 'status', placeholder: 'الحالة', options: STATUS_OPTIONS }]}
                        filterValues={filterValues}
                        onFilterChange={handleFilterChange}
                        onClearAll={handleClearAll}
                        actions={
                            canManage ? (
                                <Button size="sm" onClick={() => setStartOpen(true)}>
                                    <Plus className="size-4" /> بدء جرد جديد
                                </Button>
                            ) : undefined
                        }
                    />
                </div>

                <DataTable columns={columns} data={items.data} keyExtractor={(item) => item.id} />

                <TablePagination
                    currentPage={items.meta.current_page as number}
                    totalPages={items.meta.last_page as number}
                    totalItems={items.meta.total as number}
                    from={items.meta.from as number}
                    to={items.meta.to as number}
                    onPageChange={(page) => {
                        router.reload({ data: { page } });
                    }}
                />
            </div>

            <Dialog open={startOpen} onOpenChange={setStartOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>بدء جرد جديد</DialogTitle>
                        <DialogDescription>
                            سيتم تصوير كميات كل المنتجات النشطة في الفرع كأرصدة دفترية، ثم تُدخل الكميات
                            الفعلية وتُعتمد الفروقات كتسويات مخزون.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4">
                        {branches.length > 0 && (
                            <div className="space-y-2">
                                <Label>الفرع</Label>
                                <Select value={form.data.branch_id} onValueChange={(val) => form.setData('branch_id', val)}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="اختر الفرع" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {branches.map((branch) => (
                                            <SelectItem key={branch.id} value={branch.id.toString()}>
                                                {branch.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.branch_id && <p className="text-sm text-destructive">{form.errors.branch_id}</p>}
                            </div>
                        )}
                        {branches.length === 0 && form.errors.branch_id && (
                            <p className="text-sm text-destructive">{form.errors.branch_id}</p>
                        )}

                        <div className="space-y-2">
                            <Label>ملاحظات</Label>
                            <textarea
                                rows={3}
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                                placeholder="ملاحظات اختيارية..."
                                className="flex min-h-[64px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            {form.errors.notes && <p className="text-sm text-destructive">{form.errors.notes}</p>}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setStartOpen(false)}>
                            تراجع
                        </Button>
                        <Button onClick={handleStart} disabled={form.processing}>
                            بدء الجرد
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
