import { destroy, index } from '@/actions/App/Http/Controllers/ExpenseController';
import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import ExpenseFormModal from '@/components/expenses/expense-form-modal';
import { FilterBar } from '@/components/filter-bar';
import DateRangeBar from '@/components/reports/date-range-bar';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { TableCell, TableRow } from '@/components/ui/table';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Expense, type PaginatedExpense } from '@/types/expense';
import { router } from '@inertiajs/react';
import { Pencil, Plus, Trash2, X } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'المصروفات', href: '/expenses' }];

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

/** «15/08» من YYYY-MM-DD — يوم/شهر يكفيان في شريط الفلاتر. */
function shortDate(iso: string): string {
    const [, month, day] = iso.split('-');

    return `${day}/${month}`;
}

/**
 * وصف المدى المطبَّق على «إجمالي الفترة». بلا مدى يقرأ المستخدم الرقم على أنه
 * مصروف اليوم، فيُقال له صراحةً إنه كل الفترات.
 */
function rangeLabel(from: string, to: string): string {
    if (!from && !to) return 'كل الفترات';
    if (from && to) return `${shortDate(from)} — ${shortDate(to)}`;

    return from ? `من ${shortDate(from)}` : `حتى ${shortDate(to)}`;
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
                        <Button variant="outline" size="sm" className="text-destructive hover:text-destructive" onClick={() => setDeleting(item)}>
                            <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                    </div>
                ),
            },
        ],
        [],
    );

    // المدى التاريخي يمرّ عبر useReportFilters (كبقية الشاشات) بينما يبقى البحث
    // والفئة على آليّة FilterBar المؤجَّلة. المفتاحان from/to يدعمهما الخادم أصلاً،
    // وbuildQuery أدناه يحملهما مع كل تنقّل فلا يضيع المدى عند البحث.
    const dateDefaults = useMemo<FilterValues>(() => ({ search: '', expense_category_id: '', from: '', to: '' }), []);
    const applied: FilterValues = {
        search: filters.search ?? '',
        expense_category_id: filters.expense_category_id ?? '',
        from: filters.from ?? '',
        to: filters.to ?? '',
    };
    const dateFilters = useReportFilters(index.url(), applied, dateDefaults);
    const hasRange = dateFilters.isActive('from') || dateFilters.isActive('to');

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
                    <div className="bg-muted/40 rounded-lg border px-4 py-2 text-sm">
                        <div className="flex items-baseline gap-2">
                            <span className="text-muted-foreground">إجمالي الفترة: </span>
                            <span className="font-bold tabular-nums">{formatSar(periodTotal)}</span>
                        </div>
                        <p className="text-muted-foreground text-xs">{rangeLabel(applied.from, applied.to)}</p>
                    </div>
                </div>

                <Card className="mb-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-3 rounded-md px-4 py-3.5 sm:px-5">
                    <DateRangeBar filters={dateFilters} from={applied.from} to={applied.to} extended />
                    {hasRange && (
                        <Button type="button" variant="ghost" size="sm" onClick={() => dateFilters.replaceMany({ from: '', to: '' })}>
                            <X className="size-3" /> كل الفترات
                        </Button>
                    )}
                </Card>

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

                {/*
                    صفّ الإجماليات يقرأ periodTotal الآتي من الخادم لا مجموع صفوف الصفحة الظاهرة:
                    الجدول مصفَّح، ورقمٌ يجمع الصفحة وحدها كان سيخالف بطاقة «إجمالي الفترة» فوقه.
                    ولا تُجمع «سعر الوحدة» (متوسط لا مجموع) ولا «الكمية» (لتر وقطعة وكرتون معاً).
                */}
                <DataTable
                    columns={columns}
                    data={items.data}
                    keyExtractor={(item) => item.id}
                    footer={
                        <TableRow>
                            <TableCell className="font-bold whitespace-nowrap">الإجمالي — {rangeLabel(applied.from, applied.to)}</TableCell>
                            <TableCell />
                            <TableCell />
                            <TableCell />
                            <TableCell />
                            <TableCell className="font-bold tabular-nums">{formatSar(periodTotal)}</TableCell>
                            <TableCell />
                            <TableCell />
                        </TableRow>
                    }
                />

                <TablePagination
                    currentPage={items.meta.current_page as number}
                    totalPages={items.meta.last_page as number}
                    totalItems={items.meta.total as number}
                    onPageChange={(page) => {
                        router.reload({ data: { page } });
                    }}
                />
            </div>

            <Dialog open={!!deleting} onOpenChange={(open) => !open && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>هل أنت متأكد من حذف هذا المصروف؟ لا يمكن التراجع عن هذا الإجراء.</DialogDescription>
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
