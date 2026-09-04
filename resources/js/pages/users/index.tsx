import UserFormModal from '@/components/users/user-form-modal';
import UserServiceCommissionsModal from '@/components/users/user-service-commissions-modal';
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
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type BranchOption, type ManagedUser, type PaginatedUser, type RoleOption } from '@/types/user';
import { Link, router, usePage } from '@inertiajs/react';
import { Eye, LogIn, Pencil, Percent, Plus, StickyNote, Trash2 } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import users from '@/routes/users';
import { formatCurrency } from '@/lib/utils';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'المستخدمون', href: users.index().url },
];

// Roles that earn per-service commissions. The button only shows for these
// users when they belong to a branch.
const COMMISSION_ROLES = ['employee', 'accountant'];

// Roles that can never be impersonated. The server enforces this too (policy).
const ADMIN_ROLES = ['super-admin', 'branch-admin'];

function hasServiceCommissions(item: ManagedUser): boolean {
    return item.branchId !== null && item.role !== null && COMMISSION_ROLES.includes(item.role);
}

interface Props {
    users: PaginatedUser;
    roles: RoleOption[];
    branches: BranchOption[];
    isSuperAdmin: boolean;
    filters: {
        search?: string;
        role?: string[];
        status?: string;
        branch?: string[];
    };
}

export default function UsersIndex({ users: items, roles, branches, isSuperAdmin, filters }: Props) {
    const { auth } = usePage<SharedData>().props;
    const isAdmin = isSuperAdmin || auth.role === 'branch-admin';

    // Mirrors UserPolicy::impersonate — admins may sign in as any active
    // non-admin who isn't themselves. The server re-checks before swapping accounts.
    function canImpersonate(item: ManagedUser): boolean {
        return (
            isAdmin &&
            item.isActive &&
            item.id !== auth.user.id &&
            (item.role === null || !ADMIN_ROLES.includes(item.role))
        );
    }

    function handleImpersonate(item: ManagedUser) {
        router.post(users.impersonate(item.id).url);
    }

    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<ManagedUser | null>(null);
    const [deleting, setDeleting] = useState<ManagedUser | null>(null);
    const [commissionUser, setCommissionUser] = useState<ManagedUser | null>(null);

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
                        <Link href={users.show(item.id).url} className="font-medium hover:underline">
                            {item.name}
                        </Link>
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
                key: 'notesExcerpt',
                header: 'ملاحظات',
                cell: (item) =>
                    item.notesExcerpt ? (
                        <TooltipProvider delayDuration={100}>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span className="text-muted-foreground flex max-w-56 items-center gap-1.5 text-sm">
                                        <StickyNote className="size-3.5 shrink-0" />
                                        <span className="truncate">{item.notesExcerpt}</span>
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent className="max-w-xs whitespace-pre-wrap">
                                    {item.notesExcerpt}
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    ) : (
                        <span className="text-muted-foreground">—</span>
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
                cell: (item) => (
                    <div className="flex items-center justify-end gap-2 whitespace-nowrap">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={users.show(item.id).url}>
                                <Eye className="h-3.5 w-3.5" />
                            </Link>
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => openEdit(item)}>
                            <Pencil className="h-3.5 w-3.5" />
                        </Button>
                        {hasServiceCommissions(item) && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setCommissionUser(item)}
                                title="عمولات الخدمات"
                            >
                                <Percent className="h-3.5 w-3.5" />
                            </Button>
                        )}
                        {canImpersonate(item) && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => handleImpersonate(item)}
                                title="تسجيل الدخول كـ"
                            >
                                <LogIn className="h-3.5 w-3.5" />
                            </Button>
                        )}
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
    // Status is single-select; role and branch each accept several values.
    const [filterValues, setFilterValues] = useState<Record<string, string>>({
        status: filters.status ?? '',
    });
    const [multiValues, setMultiValues] = useState<Record<string, string[]>>({
        role: filters.role ?? [],
        branch: filters.branch ?? [],
    });
    const searchTimeout = useRef<ReturnType<typeof setTimeout>>(null);

    type FilterState = { search: string; status: string; role: string[]; branch: string[] };

    function visit(next: Partial<FilterState>) {
        const state: FilterState = {
            search,
            status: filterValues.status ?? '',
            role: multiValues.role ?? [],
            branch: multiValues.branch ?? [],
            ...next,
        };
        router.get(
            users.index().url,
            {
                ...(state.search && { search: state.search }),
                ...(state.status && { status: state.status }),
                ...(state.role.length && { role: state.role }),
                ...(state.branch.length && { branch: state.branch }),
            },
            { preserveState: true, replace: true },
        );
    }

    const handleSearchChange = (value: string) => {
        setSearch(value);
        if (searchTimeout.current) clearTimeout(searchTimeout.current);
        searchTimeout.current = setTimeout(() => {
            visit({ search: value });
        }, 400);
    };

    const handleFilterChange = (key: string, val: string) => {
        setFilterValues((prev) => ({ ...prev, [key]: val }));
        visit({ [key]: val } as Partial<FilterState>);
    };

    const handleMultiChange = (key: string, vals: string[]) => {
        setMultiValues((prev) => ({ ...prev, [key]: vals }));
        visit({ [key]: vals } as Partial<FilterState>);
    };

    const handleClearAll = () => {
        setSearch('');
        setFilterValues({ status: '' });
        setMultiValues({ role: [], branch: [] });
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
                                multiple: true,
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
                            // Only super-admins see users across branches; others are
                            // scoped to their own branch, so the filter is hidden for them.
                            ...(isSuperAdmin
                                ? [
                                      {
                                          key: 'branch',
                                          placeholder: 'الفرع',
                                          multiple: true,
                                          searchable: true,
                                          searchPlaceholder: 'بحث عن فرع...',
                                          options: branches.map((b) => ({ value: String(b.id), label: b.name })),
                                      },
                                  ]
                                : []),
                        ]}
                        filterValues={filterValues}
                        onFilterChange={handleFilterChange}
                        multiValues={multiValues}
                        onMultiChange={handleMultiChange}
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
                        router.reload({ data: { page } });
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

            <UserServiceCommissionsModal
                user={commissionUser}
                open={!!commissionUser}
                onOpenChange={(open) => !open && setCommissionUser(null)}
                canEdit
            />
        </AppLayout>
    );
}
