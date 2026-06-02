import UserFormModal from '@/components/users/user-form-modal';
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
import { type BranchOption, type ManagedUser, type PaginatedUser, type RoleOption } from '@/types/user';
import { router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import users from '@/routes/users';
import { formatCurrency } from '@/lib/utils';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'المستخدمون', href: users.index().url },
];

interface Props {
    users: PaginatedUser;
    roles: RoleOption[];
    branches: BranchOption[];
    isSuperAdmin: boolean;
    filters: {
        search?: string;
        role?: string;
        status?: string;
    };
}

export default function UsersIndex({ users: items, roles, branches, isSuperAdmin, filters }: Props) {
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<ManagedUser | null>(null);
    const [deleting, setDeleting] = useState<ManagedUser | null>(null);

    function openCreate() {
        setEditing(null);
        setFormOpen(true);
    }

    function openEdit(item: ManagedUser) {
        setEditing(item);
        setFormOpen(true);
    }

    function handleToggleStatus(item: ManagedUser) {
        router.patch(users.toggleStatus(item.id).url, {}, { preserveScroll: true });
    }

    function handleDelete() {
        if (!deleting) return;
        router.delete(users.destroy(deleting.id).url, {
            preserveScroll: true,
            onFinish: () => setDeleting(null),
        });
    }

    const columns = useMemo<ColumnDef<ManagedUser>[]>(
        () => [
            {
                key: 'name',
                header: 'الاسم',
                sortable: true,
                cell: (item) => (
                    <div className="flex flex-col">
                        <span className="font-medium">{item.name}</span>
                        <span className="font-mono text-xs text-muted-foreground" dir="ltr">@{item.username}</span>
                    </div>
                ),
            },
            {
                key: 'email',
                header: 'البريد الإلكتروني',
                cell: (item) => (
                    <span className="text-sm text-muted-foreground" dir="ltr">{item.email}</span>
                ),
            },
            {
                key: 'roleLabel',
                header: 'الدور',
                cell: (item) => (
                    <Badge variant="secondary">{item.roleLabel ?? '—'}</Badge>
                ),
            },
            {
                key: 'branchName',
                header: 'الفرع',
                cell: (item) => item.branchName ?? '—',
            },
            {
                key: 'salary',
                header: 'الراتب',
                cell: (item) => (
                    <span dir="ltr" className="tabular-nums">{formatCurrency(item.salary)}</span>
                ),
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

    const [search, setSearch] = useState(filters.search ?? '');
    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        role: filters.role ?? '',
        status: filters.status ?? '',
    });
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    function visit(next: { search?: string; role?: string; status?: string }) {
        router.get(
            users.index().url,
            {
                ...(next.search && { search: next.search }),
                ...(next.role && { role: next.role }),
                ...(next.status && { status: next.status }),
            },
            { preserveState: true, replace: true },
        );
    }

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            visit({ search: value, ...filterValues });
        }, 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        const next = { ...filterValues, [key]: val };
        setFilterValues(next);
        visit({ search, ...next });
    };

    const handleClearAll = () => {
        setSearch('');
        setFilterValues({ role: '', status: '' });
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        router.get(users.index().url, {}, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">المستخدمون</h1>
                </div>

                <div className="mb-6">
                    <FilterBar
                        searchable
                        searchPlaceholder="بحث بالاسم أو اسم المستخدم أو البريد..."
                        searchValue={search}
                        onSearchChange={handleSearchChange}
                        filters={[
                            {
                                key: 'role',
                                placeholder: 'الدور',
                                options: roles.map((r) => ({ value: r.value, label: r.label })),
                            },
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
                                <Plus className="size-4" /> إضافة مستخدم
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
                        router.get(users.index({ query: { page } }).url, {}, { preserveState: true, preserveScroll: true });
                    }}
                />
            </div>

            <Dialog open={!!deleting} onOpenChange={(open) => !open && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>
                            هل أنت متأكد من حذف المستخدم "{deleting?.name}"؟ لا يمكن التراجع عن هذا الإجراء.
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

            <UserFormModal
                key={editing?.id ?? 'create'}
                open={formOpen}
                onOpenChange={setFormOpen}
                user={editing ?? undefined}
                roles={roles}
                branches={branches}
                isSuperAdmin={isSuperAdmin}
            />
        </AppLayout>
    );
}
