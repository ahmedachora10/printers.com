import CommissionPayModal from '@/components/commissions/commission-pay-modal';
import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate } from '@/lib/utils';
import commissions from '@/routes/commissions';
import { type BreadcrumbItem } from '@/types';
import {
    type CommissionEmployeeRow,
    type CommissionPayment,
    type CommissionSummary,
    type PaginatedCommissionPayment,
} from '@/types/commission';
import { router } from '@inertiajs/react';
import { Banknote, TrendingUp, Wallet } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'العمولات', href: '/commissions' }];

interface Props {
    employees: CommissionEmployeeRow[];
    summary: CommissionSummary;
    payments: PaginatedCommissionPayment;
    branches: { id: number; name: string }[];
    isSuperAdmin: boolean;
    filters: { branch?: string };
}

export default function CommissionsIndex({ employees, summary, payments, branches, isSuperAdmin, filters }: Props) {
    const [paying, setPaying] = useState<CommissionEmployeeRow | null>(null);
    const [payOpen, setPayOpen] = useState(false);

    function openPay(employee: CommissionEmployeeRow) {
        setPaying(employee);
        setPayOpen(true);
    }

    function handleBranchChange(val: string) {
        router.get(
            commissions.index().url,
            { ...(val && val !== 'all' && { branch: val }) },
            { preserveState: true, replace: true },
        );
    }

    const employeeColumns = useMemo<ColumnDef<CommissionEmployeeRow>[]>(
        () => [
            {
                key: 'userName',
                header: 'الموظف',
                cell: (item) => <span className="font-medium">{item.userName}</span>,
            },
            {
                key: 'totalEarned',
                header: 'إجمالي العمولة',
                cell: (item) => formatCurrency(item.totalEarned),
            },
            {
                key: 'tahazirEarned',
                header: 'منها تحضير',
                cell: (item) => (
                    <span className="text-muted-foreground">{formatCurrency(item.tahazirEarned)}</span>
                ),
            },
            {
                key: 'totalPaid',
                header: 'المصروف',
                cell: (item) => <span className="text-green-600">{formatCurrency(item.totalPaid)}</span>,
            },
            {
                key: 'pending',
                header: 'المستحق',
                cell: (item) =>
                    item.pending > 0 ? (
                        <span className="font-semibold text-amber-600">{formatCurrency(item.pending)}</span>
                    ) : (
                        <span className="text-muted-foreground">—</span>
                    ),
            },
            {
                key: 'actions',
                header: '',
                headerClassName: 'w-28',
                cell: (item) => (
                    <Button size="sm" disabled={item.pending <= 0} onClick={() => openPay(item)}>
                        <Banknote className="size-4" /> صرف
                    </Button>
                ),
            },
        ],
        [],
    );

    const paymentColumns = useMemo<ColumnDef<CommissionPayment>[]>(
        () => [
            {
                key: 'userName',
                header: 'الموظف',
                cell: (item) => <span className="font-medium">{item.userName ?? '—'}</span>,
            },
            {
                key: 'period',
                header: 'الفترة',
                cell: (item) => (
                    <span className="text-sm text-muted-foreground" dir="ltr">
                        {item.periodStart ? formatDate(item.periodStart) : '—'} →{' '}
                        {item.periodEnd ? formatDate(item.periodEnd) : '—'}
                    </span>
                ),
            },
            {
                key: 'totalAmount',
                header: 'المبلغ',
                cell: (item) => <span className="font-semibold">{formatCurrency(Number(item.totalAmount))}</span>,
            },
            {
                key: 'paidByName',
                header: 'صرفها',
                cell: (item) => <span className="text-sm">{item.paidByName ?? '—'}</span>,
            },
            {
                key: 'paidAt',
                header: 'تاريخ الصرف',
                cell: (item) => (item.paidAt ? formatDate(item.paidAt) : '—'),
            },
        ],
        [],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">العمولات</h1>
                    {isSuperAdmin && branches.length > 0 && (
                        <Select value={filters.branch ?? 'all'} onValueChange={handleBranchChange}>
                            <SelectTrigger className="w-48">
                                <SelectValue placeholder="كل الفروع" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">كل الفروع</SelectItem>
                                {branches.map((b) => (
                                    <SelectItem key={b.id} value={b.id.toString()}>
                                        {b.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                </div>

                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                <TrendingUp className="size-4" /> إجمالي العمولات
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">{formatCurrency(summary.totalEarned)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                <Banknote className="size-4" /> المصروف
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-green-600">{formatCurrency(summary.totalPaid)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                <Wallet className="size-4" /> المستحق
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold text-amber-600">{formatCurrency(summary.pending)}</p>
                        </CardContent>
                    </Card>
                </div>

                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>العمولات حسب الموظف</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DataTable
                            columns={employeeColumns}
                            data={employees}
                            keyExtractor={(item) => item.userId}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>سجل صرف العمولات</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DataTable
                            columns={paymentColumns}
                            data={payments.data}
                            keyExtractor={(item) => item.id}
                        />
                        <TablePagination
                            currentPage={payments.meta.current_page as number}
                            totalPages={payments.meta.last_page as number}
                            totalItems={payments.meta.total as number}
                            onPageChange={(page) => {
                                router.reload({ data: { page } });
                            }}
                        />
                    </CardContent>
                </Card>
            </div>

            <CommissionPayModal
                key={paying?.userId ?? 'none'}
                open={payOpen}
                onOpenChange={setPayOpen}
                employee={paying}
            />
        </AppLayout>
    );
}
