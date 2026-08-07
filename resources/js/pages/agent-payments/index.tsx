import { index } from '@/actions/App/Http/Controllers/AgentPaymentController';
import PaymentFormModal from '@/components/agent-payments/payment-form-modal';
import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type AgentOutstanding, type AgentPaymentRow, type PaginatedAgentPayment } from '@/types/agent-payment';
import { router } from '@inertiajs/react';
import { Wallet } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'مدفوعات المناديب', href: '/agent-payments' }];

interface Props {
    agents: AgentOutstanding[];
    payments: PaginatedAgentPayment;
}

export default function AgentPaymentsIndex({ agents, payments }: Props) {
    const [paying, setPaying] = useState<AgentOutstanding | null>(null);

    const totalOutstanding = useMemo(
        () => agents.reduce((sum, a) => sum + a.outstandingRebate, 0),
        [agents],
    );

    const columns = useMemo<ColumnDef<AgentPaymentRow>[]>(
        () => [
            { key: 'agent', header: 'المندوب', cell: (p) => <span className="font-medium">{p.agentName ?? '—'}</span> },
            { key: 'branch', header: 'الفرع', cell: (p) => p.branchName ?? '—' },
            {
                key: 'period',
                header: 'الفترة',
                cell: (p) => (
                    <span className="whitespace-nowrap tabular-nums" dir="ltr">
                        {p.periodStart} — {p.periodEnd}
                    </span>
                ),
            },
            { key: 'invoices', header: 'الفواتير', cell: (p) => <span className="tabular-nums">{p.totalInvoices}</span> },
            {
                key: 'rebate',
                header: 'الإجمالي',
                cell: (p) => <span className="font-semibold tabular-nums">{formatCurrency(p.totalRebate)}</span>,
            },
            { key: 'paidBy', header: 'بواسطة', cell: (p) => p.paidBy ?? '—' },
            { key: 'paidAt', header: 'التاريخ', cell: (p) => <span className="tabular-nums" dir="ltr">{p.paidAt}</span> },
        ],
        [],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <h1 className="text-2xl font-bold">مدفوعات المناديب</h1>
                    <div className="rounded-lg border bg-muted/40 px-4 py-2 text-sm">
                        <span className="text-muted-foreground">إجمالي العمولات المستحقة: </span>
                        <span className="font-bold tabular-nums">{formatCurrency(totalOutstanding)}</span>
                    </div>
                </div>

                {/* Outstanding rebate per agent */}
                <Card className="mb-6">
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">العمولات المستحقة</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {agents.length === 0 ? (
                            <p className="text-muted-foreground text-sm">لا يوجد مناديب.</p>
                        ) : (
                            <div className="divide-y">
                                {agents.map((a) => (
                                    <div key={`${a.id}-${a.branchId}`} className="flex items-center justify-between gap-4 py-3">
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">{a.name}</span>
                                            {/* Each branch of a multi-branch agent is settled on its own row. */}
                                            <Badge variant="secondary">{a.branchName}</Badge>
                                            {!a.isActive && (
                                                <Badge variant="outline" className="text-muted-foreground">
                                                    غير نشط
                                                </Badge>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-4">
                                            <div className="text-left">
                                                <span className="font-semibold tabular-nums">
                                                    {formatCurrency(a.outstandingRebate)}
                                                </span>
                                                <span className="text-muted-foreground mr-2 text-xs">
                                                    ({a.outstandingInvoices} فاتورة)
                                                </span>
                                            </div>
                                            <Button
                                                size="sm"
                                                disabled={a.outstandingRebate <= 0}
                                                onClick={() => setPaying(a)}
                                            >
                                                <Wallet className="size-4" /> دفع
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Payment history */}
                <h2 className="mb-3 text-lg font-semibold">سجل الدفعات</h2>
                <DataTable columns={columns} data={payments.data} keyExtractor={(p) => p.id} />
                <TablePagination
                    currentPage={payments.meta.current_page as number}
                    totalPages={payments.meta.last_page as number}
                    totalItems={payments.meta.total as number}
                    onPageChange={(page) =>
                        router.reload({ data: { page } })
                    }
                />
            </div>

            <PaymentFormModal
                key={paying ? `${paying.id}-${paying.branchId}` : 'none'}
                open={!!paying}
                onOpenChange={(open) => !open && setPaying(null)}
                agent={paying}
            />
        </AppLayout>
    );
}
