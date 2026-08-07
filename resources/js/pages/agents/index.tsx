import { destroy, index } from '@/actions/App/Http/Controllers/AgentController';
import AgentFormModal from '@/components/agents/agent-form-modal';
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
import { type Agent, type EnumOption, type PaginatedAgent } from '@/types/agent';
import { router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'المناديب', href: '/agents' },
];

interface Props {
    items: PaginatedAgent;
    agentTypes: EnumOption[];
    discountModes: EnumOption[];
    branches?: { id: number; name: string }[] | null;
    filters: {
        search?: string;
        agent_type?: string;
        discount_mode?: string;
        status?: string;
        branch_id?: string;
    };
}

export default function AgentsIndex({ items, agentTypes, discountModes, branches, filters }: Props) {
    const isSuperAdmin = Array.isArray(branches);
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Agent | null>(null);
    const [deleting, setDeleting] = useState<Agent | null>(null);

    function openCreate() {
        setEditing(null);
        setFormOpen(true);
    }

    function openEdit(item: Agent) {
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

    const columns = useMemo<ColumnDef<Agent>[]>(
        () => [
            {
                key: 'name',
                header: 'الاسم',
                cell: (item) => <span className="font-medium">{item.name}</span>,
            },
            {
                key: 'username',
                header: 'اسم المستخدم',
                cell: (item) => <span className="tabular-nums" dir="ltr">{item.username}</span>,
            },
            ...(isSuperAdmin
                ? [
                      {
                          key: 'branch',
                          header: 'الفروع',
                          // An agent may work with several branches at once.
                          cell: (item: Agent) =>
                              item.branches?.length
                                  ? item.branches.map((b) => b.branchName).join('، ')
                                  : (item.branchName ?? '—'),
                      },
                  ]
                : []),
            {
                key: 'type',
                header: 'النوع',
                cell: (item) => item.agentType?.label ?? '—',
            },
            {
                key: 'phone',
                header: 'الهاتف',
                cell: (item) => <span className="whitespace-nowrap tabular-nums" dir="ltr">{item.phone ?? '—'}</span>,
            },
            {
                key: 'mode',
                header: 'نمط العمولة',
                // Terms are per branch, so show «متعدد» rather than one branch's
                // mode standing in for all of them.
                cell: (item) => {
                    const modes = new Set(item.branches?.map((b) => b.discountMode));

                    return modes.size > 1 ? 'متعدد' : (item.discountMode?.label ?? '—');
                },
            },
            {
                key: 'rate',
                header: 'القيمة',
                cell: (item) => {
                    const rates = new Set(item.branches?.map((b) => `${b.rate}-${b.discountType}`));

                    if (rates.size > 1) {
                        return <span className="text-muted-foreground">متعدد</span>;
                    }

                    const terms = item.branches?.[0];

                    return (
                        <span className="tabular-nums">
                            {terms?.rate ?? item.rate}
                            {(terms?.discountType ?? item.discountType?.value) === 'fixed' ? ' ر.س' : '%'}
                        </span>
                    );
                },
            },
            {
                key: 'status',
                header: 'الحالة',
                cell: (item) =>
                    item.isActive ? (
                        <Badge variant="secondary">نشط</Badge>
                    ) : (
                        <Badge variant="outline" className="text-muted-foreground">غير نشط</Badge>
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
        [isSuperAdmin],
    );

    const [search, setSearch] = useState(filters.search ?? '');
    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        agent_type: filters.agent_type ?? '',
        discount_mode: filters.discount_mode ?? '',
        status: filters.status ?? '',
        branch_id: filters.branch_id ?? '',
    });
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    const buildQuery = (overrides: Record<string, string | undefined>) => {
        const params: Record<string, string> = {};
        const merged = {
            search,
            agent_type: filterValues.agent_type,
            discount_mode: filterValues.discount_mode,
            status: filterValues.status,
            branch_id: filterValues.branch_id,
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
        setFilterValues({ agent_type: '', discount_mode: '', status: '', branch_id: '' });
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get(index.url(), {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">المناديب</h1>
                </div>

                <div className="mb-6">
                    <FilterBar
                        searchable
                        searchPlaceholder="بحث بالاسم أو رقم الهاتف..."
                        searchValue={search}
                        onSearchChange={handleSearchChange}
                        filters={[
                            ...(isSuperAdmin
                                ? [
                                      {
                                          key: 'branch_id',
                                          placeholder: 'الفرع',
                                          options: branches!.map((b) => ({ value: b.id.toString(), label: b.name })),
                                      },
                                  ]
                                : []),
                            {
                                key: 'agent_type',
                                placeholder: 'النوع',
                                options: agentTypes.map((t) => ({ value: t.value, label: t.label })),
                            },
                            {
                                key: 'discount_mode',
                                placeholder: 'نمط العمولة',
                                options: discountModes.map((m) => ({ value: m.value, label: m.label })),
                            },
                            {
                                key: 'status',
                                placeholder: 'الحالة',
                                options: [
                                    { value: 'active', label: 'نشط' },
                                    { value: 'inactive', label: 'غير نشط' },
                                ],
                            },
                        ]}
                        filterValues={filterValues}
                        onFilterChange={handleFilterChange}
                        onClearAll={handleClearAll}
                        actions={
                            <Button size="sm" onClick={openCreate}>
                                <Plus className="size-4" /> إضافة مندوب
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
                        router.reload({ data: { page } });
                    }}
                />
            </div>

            <Dialog open={!!deleting} onOpenChange={(open) => !open && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>
                            هل أنت متأكد من حذف المندوب «{deleting?.name}»؟ لا يمكن التراجع عن هذا الإجراء.
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

            <AgentFormModal
                key={editing?.id ?? 'create'}
                open={formOpen}
                onOpenChange={setFormOpen}
                agent={editing ?? undefined}
                agentTypes={agentTypes}
                discountModes={discountModes}
                branches={branches}
            />
        </AppLayout>
    );
}
