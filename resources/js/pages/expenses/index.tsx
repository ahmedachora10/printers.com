import { destroy, index } from '@/actions/App/Http/Controllers/ExpenseController';
import ExpenseFormModal from '@/components/expenses/expense-form-modal';
import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { FilterBar } from '@/components/filter-bar';
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
import { type Expense, type PaginatedExpense } from '@/types/expense';
import { router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'المصروفات', href: '/expenses' },
];

interface Category {
    id: number;
    name: string;
}

interface Props {
    items: PaginatedExpense;
    periodTotal: number;
    categories: Category[];
    branches?: { id: number; name: string }[] | null;
    filters: {
        search?: string;
        expense_category_id?: string;
        from?: string;
        to?: string;
    };
}

function formatSar(value: number): string {
    return value.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ر.س';
}

export default function ExpensesIndex({ items, periodTotal, categories, branches, filters }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Expense | null>(null);
    const [deleting, setDeleting] = useState<Expense | null>(null);

    function openCreate() {
        setEditing(null);
        setFormOpen(true);
    }

    function openEdit(item: Expense) {
        setEditing(item);
        setFormOpen(true);
    }

    function handleDelete() {
        if (!deleting) return;
        router.delete(destroy.url(deleting), {
            preserveScroll: true,
            onFinish: () => setDeleting(null),
        });
    }

    const columns = useMemo<ColumnDef<Expense>[]>(
        () => [
            {
                key: 'date',
                header: 'التاريخ',
                cell: (item) => <span className="whitespace-nowrap tabular-nums">{item.dateLabel}</span>,
            },
            {
                key: 'category',
                header: 'الفئة',
                cell: (item) => <span className="font-medium">{item.categoryName}</span>,
            },
            {
                key: 'supplier',
                header: 'المورّد',
                cell: (item) => item.supplierName ?? '—',
            },
            {
                key: 'qty',
                header: 'الكمية',
                cell: (item) => <span className="tabular-nums">{item.qty}</span>,
            },
            {
                key: 'unitPrice',
                header: 'سعر الوحدة',
                cell: (item) => <span className="tabular-nums">{formatSar(item.unitPrice)}</span>,
            },
            {
                key: 'total',
                header: 'الإجمالي',
                cell: (item) => <span className="font-semibold tabular-nums">{formatSar(item.total)}</span>,
            },
            {
                key: 'user',
                header: 'بواسطة',
                cell: (item) => item.userName ?? '—',
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

    const [search, setSearch] = useState(filters.search ?? '');
    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        expense_category_id: filters.expense_category_id ?? '',
    });
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    const buildQuery = (overrides: Record<string, string | undefined>) => {
        const params: Record<string, string> = {};
        const merged = {
            search,
            expense_category_id: filterValues.expense_category_id,
            from: filters.from,
            to: filters.to,
            ...overrides,
        };
        Object.entries(merged).forEach(([key, value]) => {
            if (value) params[key] = value;
        });
        return params;
    };

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            router.get(index.url(), buildQuery({ search: value }), { preserveState: true, replace: true });
        }, 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        router.get(index.url(), buildQuery({ [key]: val }), { preserveState: true, replace: true });
    };

    const handleClearAll = () => {
        setSearch('');
        setFilterValues({ expense_category_id: '' });
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get(index.url(), {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">المصروفات</h1>
                    <div className="rounded-lg border bg-muted/40 px-4 py-2 text-sm">
                        <span className="text-muted-foreground">إجمالي الفترة: </span>
                        <span className="font-bold tabular-nums">{formatSar(periodTotal)}</span>
                    </div>
                </div>

                <div className="mb-6">
                    <FilterBar
                        searchable
                        searchPlaceholder="بحث بالمورّد أو مرجع الإيصال..."
                        searchValue={search}
                        onSearchChange={handleSearchChange}
                        filters={[
                            {
                                key: 'expense_category_id',
                                placeholder: 'الفئة',
                                options: categories.map((c) => ({ value: c.id.toString(), label: c.name })),
                            },
                        ]}
                        filterValues={filterValues}
                        onFilterChange={handleFilterChange}
                        onClearAll={handleClearAll}
                        actions={
                            <Button size="sm" onClick={openCreate}>
                                <Plus className="size-4" /> تسجيل مصروف
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
                        router.get(index.url(), { ...buildQuery({}), page: String(page) }, { preserveState: true, preserveScroll: true });
                    }}
                />
            </div>

            <Dialog open={!!deleting} onOpenChange={(open) => !open && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>
                            هل أنت متأكد من حذف هذا المصروف؟ لا يمكن التراجع عن هذا الإجراء.
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

            <ExpenseFormModal
                key={editing?.id ?? 'create'}
                open={formOpen}
                onOpenChange={setFormOpen}
                expense={editing ?? undefined}
                categories={categories}
                branches={branches}
            />
        </AppLayout>
    );
}
