import { DataTable, type ColumnDef } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { RichTextView } from '@/components/ui/rich-text-view';
import UserFormModal from '@/components/users/user-form-modal';
import UserServiceCommissionsCard from '@/components/users/user-service-commissions-card';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate } from '@/lib/utils';
import users from '@/routes/users';
import { type BreadcrumbItem } from '@/types';
import {
    type BranchOption,
    type ManagedUser,
    type RoleOption,
    type UserCommissionLedgerItem,
    type UserCommissionSummary,
    type UserInvoiceHistoryItem,
    type UserSalesSummary,
    type UserServiceCommission,
} from '@/types/user';
import { router } from '@inertiajs/react';
import { Briefcase, CreditCard, Pencil, Phone, Receipt, StickyNote, Trash2, TrendingUp, User } from 'lucide-react';
import { useState } from 'react';

const STATUS_COLORS: Record<string, string> = {
    paid: 'border-green-200 bg-green-50 text-green-700',
    partially_paid: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300',
    due: 'border-red-200 bg-red-50 text-red-700',
    cancelled: 'border-border bg-muted/60 text-muted-foreground',
    returned: 'border-red-300 bg-red-100 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-300',
};

const STATUS_LABELS: Record<string, string> = {
    paid: 'مدفوع',
    partially_paid: 'مدفوع جزئياً',
    due: 'مستحق',
    cancelled: 'ملغي',
    returned: 'مرتجع',
};

const invoiceHistoryColumns: ColumnDef<UserInvoiceHistoryItem>[] = [
    { key: 'invoice_number', header: 'رقم الفاتورة', className: 'font-mono text-xs', cell: (inv) => inv.invoice_number },
    {
        key: 'type',
        header: 'النوع',
        cell: (inv) => (
            <Badge variant="secondary" className="text-xs">
                {inv.type === 'service' ? 'خدمة' : 'منتج'}
            </Badge>
        ),
    },
    {
        key: 'total_amount',
        header: 'المبلغ',
        className: 'tabular-nums',
        cell: (inv) => <span dir="ltr">{formatCurrency(Number(inv.total_amount))}</span>,
    },
    {
        key: 'status',
        header: 'الحالة',
        cell: (inv) => (
            <Badge variant="outline" className={STATUS_COLORS[inv.status]}>
                {STATUS_LABELS[inv.status] ?? inv.status}
            </Badge>
        ),
    },
    { key: 'created_at', header: 'التاريخ', className: 'text-muted-foreground', cell: (inv) => formatDate(inv.created_at) },
];

interface Props {
    user: ManagedUser;
    commissionSummary: UserCommissionSummary;
    salesSummary: UserSalesSummary;
    commissionHistory: UserCommissionLedgerItem[];
    invoiceHistory: UserInvoiceHistoryItem[];
    serviceCommissions: UserServiceCommission[];
    roles: RoleOption[];
    branches: BranchOption[];
    isSuperAdmin: boolean;
}

export default function UserShow({
    user,
    commissionSummary,
    salesSummary,
    commissionHistory,
    invoiceHistory,
    serviceCommissions,
    roles,
    branches,
    isSuperAdmin,
}: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'المستخدمون', href: users.index().url },
        { title: user.name, href: users.show(user.id).url },
    ];

    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    function handleToggleStatus() {
        router.patch(users.toggleStatus(user.id).url, {}, { preserveScroll: true });
    }

    function handleDelete() {
        router.delete(users.destroy(user.id).url, {
            onFinish: () => setDeleteOpen(false),
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <div className="bg-muted flex size-16 items-center justify-center rounded-full">
                            <User className="text-muted-foreground size-8" />
                        </div>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-2xl font-bold">{user.name}</h1>
                                {user.roleLabel && <Badge variant="secondary">{user.roleLabel}</Badge>}
                                {user.isActive ? (
                                    <Badge variant="outline" className="gap-1.5 border-green-200 bg-green-50 text-green-700">
                                        <span className="inline-block size-1.5 rounded-full bg-green-500" />
                                        نشط
                                    </Badge>
                                ) : (
                                    <Badge variant="secondary">غير نشط</Badge>
                                )}
                            </div>
                            <p className="text-muted-foreground font-mono text-sm" dir="ltr">
                                @{user.username}
                            </p>
                            <div className="mt-1 flex flex-wrap items-center gap-3 text-sm">
                                {user.branchName && <span className="text-muted-foreground">{user.branchName}</span>}
                                {user.phone && (
                                    <a
                                        href={`https://wa.me/${user.phone}`}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="flex items-center gap-1.5 text-green-700 hover:underline"
                                    >
                                        <Phone className="size-3.5" />
                                        <span dir="ltr">{user.phone}</span>
                                    </a>
                                )}
                                <span className="text-muted-foreground" dir="ltr">
                                    {user.email}
                                </span>
                                {user.joinedDate && <span className="text-muted-foreground">انضمّ في {formatDate(user.joinedDate)}</span>}
                            </div>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" onClick={() => setEditOpen(true)}>
                            <Pencil className="size-4" />
                            تعديل
                        </Button>
                        <Button variant="outline" size="sm" onClick={handleToggleStatus}>
                            {user.isActive ? 'تعطيل' : 'تفعيل'}
                        </Button>
                        <Button variant="outline" size="sm" className="text-destructive hover:text-destructive" onClick={() => setDeleteOpen(true)}>
                            <Trash2 className="size-4" />
                            حذف
                        </Button>
                        {/* TODO(M15): link to commissions.payments once the commission payments module exists */}
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* Left column */}
                    <div className="space-y-6 lg:col-span-2">
                        {/* Compensation */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Briefcase className="size-5" />
                                    التعويضات
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                                    <div className="bg-muted/40 rounded-lg p-4">
                                        <p className="text-muted-foreground text-sm">الراتب</p>
                                        <p className="mt-1 text-lg font-bold tabular-nums" dir="ltr">
                                            {formatCurrency(user.salary)}
                                        </p>
                                    </div>
                                    <div className="bg-muted/40 rounded-lg p-4">
                                        <p className="text-muted-foreground text-sm">نسبة العمولة الأساسية</p>
                                        <p className="mt-1 text-lg font-bold tabular-nums" dir="ltr">
                                            {user.baseCommissionPct}%
                                        </p>
                                    </div>
                                    <div className="bg-muted/40 rounded-lg p-4">
                                        <p className="text-muted-foreground text-sm">نسبة عمولة الإحالة</p>
                                        <p className="mt-1 text-lg font-bold tabular-nums" dir="ltr">
                                            {user.referralCommissionPct}%
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Administrative notes */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <StickyNote className="size-5" />
                                    ملاحظات
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {user.notes ? (
                                    <RichTextView html={user.notes} />
                                ) : (
                                    <p className="text-muted-foreground py-4 text-center text-sm">لا توجد ملاحظات</p>
                                )}
                            </CardContent>
                        </Card>

                        {/* Sales activity */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Receipt className="size-5" />
                                    النشاط البيعي
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                                    <div className="bg-muted/40 rounded-lg p-4">
                                        <p className="text-muted-foreground text-sm">فواتير الخدمات</p>
                                        <p className="mt-1 text-2xl font-bold tabular-nums">{salesSummary.serviceCount}</p>
                                    </div>
                                    <div className="bg-muted/40 rounded-lg p-4">
                                        <p className="text-muted-foreground text-sm">إجمالي الخدمات</p>
                                        <p className="mt-1 text-lg font-bold tabular-nums" dir="ltr">
                                            {formatCurrency(salesSummary.serviceTotal)}
                                        </p>
                                    </div>
                                    <div className="bg-muted/40 rounded-lg p-4">
                                        <p className="text-muted-foreground text-sm">فواتير المنتجات</p>
                                        <p className="mt-1 text-2xl font-bold tabular-nums">{salesSummary.productCount}</p>
                                    </div>
                                    <div className="bg-muted/40 rounded-lg p-4">
                                        <p className="text-muted-foreground text-sm">إجمالي المنتجات</p>
                                        <p className="mt-1 text-lg font-bold tabular-nums" dir="ltr">
                                            {formatCurrency(salesSummary.productTotal)}
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Invoice history */}
                        <Card>
                            <CardHeader>
                                <CardTitle>أحدث الفواتير</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <DataTable
                                    className="rounded-none bg-transparent shadow-none"
                                    columns={invoiceHistoryColumns}
                                    data={invoiceHistory}
                                    keyExtractor={(inv) => `${inv.type}-${inv.id}`}
                                    emptyState={<span className="text-muted-foreground text-sm">لا توجد فواتير مسجّلة</span>}
                                />
                            </CardContent>
                        </Card>
                    </div>

                    {/* Right column */}
                    <div className="space-y-6">
                        {/* Commission summary */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <CreditCard className="size-5" />
                                    ملخص العمولات
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground text-sm">إجمالي المكتسب</span>
                                    <span className="font-bold tabular-nums" dir="ltr">
                                        {formatCurrency(commissionSummary.totalEarned)}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground text-sm">إجمالي المدفوع</span>
                                    <span className="font-medium text-green-700 tabular-nums" dir="ltr">
                                        {formatCurrency(commissionSummary.totalPaid)}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between border-t pt-3">
                                    <span className="text-muted-foreground text-sm">المستحق</span>
                                    <span className="text-destructive font-bold tabular-nums" dir="ltr">
                                        {formatCurrency(commissionSummary.pending)}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between border-t pt-3 text-sm">
                                    <span className="text-muted-foreground">قياسية</span>
                                    <span className="tabular-nums" dir="ltr">
                                        {formatCurrency(commissionSummary.standardEarned)}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">تحضير</span>
                                    <span className="tabular-nums" dir="ltr">
                                        {formatCurrency(commissionSummary.tahazirEarned)}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Per-employee service commission rates */}
                        <UserServiceCommissionsCard userId={user.id} canEdit services={serviceCommissions} />

                        {/* Commission history */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <TrendingUp className="size-5" />
                                    سجل العمولات
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {commissionHistory.length === 0 ? (
                                    <p className="text-muted-foreground py-6 text-center text-sm">لا توجد عمولات مسجّلة</p>
                                ) : (
                                    <div className="space-y-2">
                                        {commissionHistory.map((tx) => (
                                            <div key={tx.id} className="bg-muted/40 flex items-center justify-between rounded-lg px-3 py-2 text-sm">
                                                <div>
                                                    <span
                                                        className={`font-bold tabular-nums ${tx.paid_at ? 'text-green-700' : 'text-amber-600'}`}
                                                        dir="ltr"
                                                    >
                                                        {formatCurrency(Number(tx.amount))}
                                                    </span>
                                                    <p className="text-muted-foreground text-xs">
                                                        {tx.paid_at ? 'مدفوعة' : 'مستحقة'}
                                                        {tx.is_tahazir ? ' · تحضير' : ''}
                                                    </p>
                                                </div>
                                                <span className="text-muted-foreground text-xs">{formatDate(tx.earned_at)}</span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>

            <UserFormModal
                key={user.id}
                open={editOpen}
                onOpenChange={setEditOpen}
                user={user}
                roles={roles}
                branches={branches}
                isSuperAdmin={isSuperAdmin}
            />

            <Dialog open={deleteOpen} onOpenChange={setDeleteOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>هل أنت متأكد من حذف المستخدم "{user.name}"؟ لا يمكن التراجع عن هذا الإجراء.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeleteOpen(false)}>
                            إلغاء
                        </Button>
                        <Button variant="destructive" onClick={handleDelete}>
                            حذف
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
