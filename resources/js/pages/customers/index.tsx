import CustomerFormModal from '@/components/customers/customer-form-modal';
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
import { formatCurrency } from '@/lib/utils';
import { Agent, type BreadcrumbItem } from '@/types';
import {
    type Customer,
    type CustomerPageStats,
    type PaginatedCustomer,
} from '@/types/customer';
import { Link, router } from '@inertiajs/react';
import { Download, Eye, Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'العملاء', href: '/customers' }];

const TIER_COLORS: Record<string, string> = {
    none: 'border-border bg-muted/60 text-muted-foreground',
    bronze: 'border-amber-200 bg-amber-50 text-amber-700',
    silver: 'border-slate-200 bg-slate-50 text-slate-600',
    gold: 'border-yellow-200 bg-yellow-50 text-yellow-700',
};

interface Branch {
    id: number;
    name: string;
}

interface Props {
    items: PaginatedCustomer;
    stats: CustomerPageStats;
    agents: Agent[];
    branches: Branch[];
    isSuperAdmin: boolean;
    filters: {
        search?: string;
        tier?: string;
        type?: string;
        agent_id?: string;
        has_outstanding?: string;
        branch_id?: string;
    };
}

function daysSince(dateStr: string | null): number | null {
    if (!dateStr) return null;
    return Math.floor((Date.now() - new Date(dateStr).getTime()) / 86_400_000);
}

function DaysChip({ dateStr }: { dateStr: string | null }) {
    const days = daysSince(dateStr);
    if (days === null) return <span className="text-muted-foreground">—</span>;
    const cls =
        days < 30
            ? 'text-green-700 bg-green-50 border-green-200'
            : days <= 90
                ? 'text-amber-700 bg-amber-50 border-amber-200'
                : 'text-red-700 bg-red-50 border-red-200';
    return (
        <span className={`inline-flex rounded-full border px-2 py-0.5 text-xs font-medium ${cls}`}>
            {days} يوم
        </span>
    );
}

export default function CustomersIndex({ items, stats, agents, branches, isSuperAdmin, filters }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Customer | null>(null);
    const [deleting, setDeleting] = useState<Customer | null>(null);

    function openCreate() {
        setEditing(null);
        setFormOpen(true);
    }

    function openEdit(item: Customer) {
        setEditing(item);
        setFormOpen(true);
    }

    const [search, setSearch] = useState(filters.search ?? '');
    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        tier: filters.tier ?? '',
        type: filters.type ?? '',
        agent_id: filters.agent_id ?? '',
        has_outstanding: filters.has_outstanding ?? '',
        branch_id: filters.branch_id ?? '',
    });
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    function buildParams(s: string, fv: Record<string, string>) {
        return Object.fromEntries(
            Object.entries({ search: s, ...fv }).filter(([, v]) => v !== ''),
        );
    }

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            router.get('/customers', buildParams(value, filterValues), { preserveState: true, replace: true });
        }, 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        router.get('/customers', buildParams(search, next), { preserveState: true, replace: true });
    };

    const handleClearAll = () => {
        setSearch('');
        setFilterValues({ tier: '', type: '', agent_id: '', has_outstanding: '', branch_id: '' });
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get('/customers', {}, { preserveState: true, replace: true });
    };

    function handleDelete() {
        if (!deleting) return;
        router.delete(`/customers/${deleting.id}`, { onFinish: () => setDeleting(null) });
    }

    const columns = useMemo<ColumnDef<Customer>[]>(
        () => [
            {
                key: 'fullName',
                header: 'الاسم',
                sortable: true,
                cell: (item) => (
                    <div>
                        <Link
                            href={`/customers/${item.id}`}
                            className="font-medium text-foreground hover:underline"
                        >
                            {item.fullName}
                        </Link>
                        {item.companyName && (
                            <p className="text-xs text-muted-foreground">{item.companyName}</p>
                        )}
                    </div>
                ),
            },
            {
                key: 'phone',
                header: 'الهاتف',
                cell: (item) => (
                    <a
                        href={`https://wa.me/${item.phone}`}
                        target="_blank"
                        rel="noreferrer"
                        className="font-mono text-sm text-green-700 hover:underline"
                        dir="ltr"
                    >
                        {item.phone}
                    </a>
                ),
            },
            ...(isSuperAdmin
                ? [
                      {
                          key: 'branchName',
                          header: 'الفرع',
                          cell: (item: Customer) =>
                              item.branchName ?? <span className="text-muted-foreground">—</span>,
                      },
                  ]
                : []),
            {
                key: 'customerType',
                header: 'النوع',
                cell: (item) => (
                    <Badge variant="secondary">{item.customerType.label}</Badge>
                ),
            },
            {
                key: 'tier',
                header: 'المستوى',
                cell: (item) => (
                    <Badge
                        variant="outline"
                        className={TIER_COLORS[item.tier.value]}
                    >
                        {item.tier.label}
                    </Badge>
                ),
            },
            {
                key: 'invoiceCount',
                header: 'الفواتير',
                cell: (item) => (
                    <span className="tabular-nums">
                        {stats[item.id]?.invoiceCount ?? 0}
                    </span>
                ),
            },
            {
                key: 'cumulativeSpend',
                header: 'إجمالي الإنفاق',
                sortable: true,
                cell: (item) => (
                    <span className="tabular-nums" dir="ltr">
                        {formatCurrency(item.cumulativeSpend)}
                    </span>
                ),
            },
            {
                key: 'lastVisit',
                header: 'آخر زيارة',
                cell: (item) => (
                    <DaysChip dateStr={stats[item.id]?.lastVisit ?? null} />
                ),
            },
            {
                key: 'outstanding',
                header: 'المديونية',
                cell: (item) => {
                    const amt = stats[item.id]?.outstanding ?? 0;
                    return amt > 0 ? (
                        <span className="font-semibold tabular-nums text-destructive" dir="ltr">
                            {formatCurrency(amt)}
                        </span>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    );
                },
            },
            {
                key: 'isActive',
                header: 'الحالة',
                cell: (item) => (
                    <button
                        onClick={() =>
                            router.patch(`/customers/${item.id}/toggle-status`, {}, { preserveScroll: true })
                        }
                        className="cursor-pointer"
                    >
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
                headerClassName: 'w-28',
                cell: (item) => (
                    <div className="flex items-center gap-1.5">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/customers/${item.id}`}>
                                <Eye className="h-3.5 w-3.5" />
                            </Link>
                        </Button>
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
        [stats, isSuperAdmin],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">العملاء</h1>
                </div>

                <div className="mb-6">
                    <FilterBar
                        searchable
                        searchPlaceholder="بحث بالاسم أو الهاتف..."
                        searchValue={search}
                        onSearchChange={handleSearchChange}
                        filters={[
                            {
                                key: 'tier',
                                placeholder: 'المستوى',
                                options: [
                                    { value: 'none', label: 'بدون' },
                                    { value: 'bronze', label: 'برونزي' },
                                    { value: 'silver', label: 'فضي' },
                                    { value: 'gold', label: 'ذهبي' },
                                ],
                            },
                            {
                                key: 'type',
                                placeholder: 'النوع',
                                options: [
                                    { value: 'individual', label: 'فردي' },
                                    { value: 'corporate', label: 'مؤسسة' },
                                ],
                            },
                            {
                                key: 'agent_id',
                                placeholder: 'المندوب',
                                options: agents.map((a) => ({ value: String(a.id), label: a.name })),
                            },
                            {
                                key: 'has_outstanding',
                                placeholder: 'المديونية',
                                options: [{ value: '1', label: 'لديه مديونية' }],
                            },
                            // Only super-admins browse across branches, so only they get the picker.
                            ...(isSuperAdmin
                                ? [
                                      {
                                          key: 'branch_id',
                                          placeholder: 'الفرع',
                                          options: branches.map((b) => ({
                                              value: String(b.id),
                                              label: b.name,
                                          })),
                                      },
                                  ]
                                : []),
                        ]}
                        filterValues={filterValues}
                        onFilterChange={handleFilterChange}
                        onClearAll={handleClearAll}
                        actions={
                            <div className="flex items-center gap-2">
                                <Button variant="outline" size="sm" asChild>
                                    <a href="/customers/export">
                                        <Download className="size-4" />
                                        تصدير
                                    </a>
                                </Button>
                                <Button size="sm" onClick={openCreate}>
                                    <Plus className="size-4" />
                                    إضافة عميل
                                </Button>
                            </div>
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
                    from={items.meta.from as number}
                    to={items.meta.to as number}
                    onPageChange={(page) => {
                        router.reload({ data: { page } });
                    }}
                />
            </div>

            <Dialog open={!!deleting} onOpenChange={(open) => !open && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>
                            هل أنت متأكد من حذف العميل "{deleting?.fullName}"؟ لا يمكن التراجع عن هذا الإجراء.
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

            <CustomerFormModal
                key={editing?.id ?? 'create'}
                open={formOpen}
                onOpenChange={setFormOpen}
                customer={editing ?? undefined}
                agents={agents}
                branches={branches}
                isSuperAdmin={isSuperAdmin}
            />
        </AppLayout>
    );
}
