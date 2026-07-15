import { DataTable, type ColumnDef } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { useMemo } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'بوابة الوكيل', href: '/agent-portal' }];

interface AgentInfo {
    name: string;
    branchName: string | null;
    discountMode: 'discount' | 'rebate' | null;
    discountModeLabel: string | null;
    discountType: 'percentage' | 'fixed';
    rate: number;
}

interface Summary {
    invoiceCount: number;
    rebateEarned: number;
    rebatePaid: number;
    rebateOutstanding: number;
    discountGiven: number;
}

interface InvoiceRow {
    type: 'product' | 'service';
    invoiceNumber: string;
    totalAmount: number;
    rebate: number;
    discount: number;
    isRebatePaid: boolean;
    status: string;
    statusLabel: string;
    createdAt: string | null;
}

interface PaymentRow {
    periodStart: string;
    periodEnd: string;
    totalInvoices: number;
    totalRebate: number;
    paidAt: string | null;
    notes: string | null;
}

interface Props {
    agent: AgentInfo;
    summary: Summary;
    recentInvoices: InvoiceRow[];
    payments: PaymentRow[];
}

function StatCard({ label, value, accent }: { label: string; value: string; accent?: boolean }) {
    return (
        <Card>
            <CardContent className="py-4">
                <p className="text-muted-foreground text-xs">{label}</p>
                <p className={`mt-1 text-xl font-bold tabular-nums ${accent ? 'text-primary' : ''}`}>{value}</p>
            </CardContent>
        </Card>
    );
}

export default function AgentPortalIndex({ agent, summary, recentInvoices, payments }: Props) {
    const isRebate = agent.discountMode === 'rebate';

    const invoiceColumns = useMemo<ColumnDef<InvoiceRow>[]>(
        () => [
            { key: 'number', header: 'رقم الفاتورة', cell: (i) => <span className="tabular-nums" dir="ltr">{i.invoiceNumber}</span> },
            { key: 'type', header: 'النوع', cell: (i) => (i.type === 'service' ? 'خدمة' : 'منتجات') },
            { key: 'date', header: 'التاريخ', cell: (i) => <span className="tabular-nums" dir="ltr">{i.createdAt}</span> },
            { key: 'total', header: 'إجمالي الفاتورة', cell: (i) => <span className="tabular-nums">{formatCurrency(i.totalAmount)}</span> },
            {
                key: 'amount',
                header: isRebate ? 'العمولة' : 'الخصم',
                cell: (i) => <span className="font-semibold tabular-nums">{formatCurrency(isRebate ? i.rebate : i.discount)}</span>,
            },
            ...(isRebate
                ? [
                      {
                          key: 'paid',
                          header: 'حالة العمولة',
                          cell: (i: InvoiceRow) =>
                              i.rebate <= 0 ? (
                                  <span className="text-muted-foreground">—</span>
                              ) : i.isRebatePaid ? (
                                  <Badge variant="secondary">مدفوعة</Badge>
                              ) : (
                                  <Badge variant="outline" className="text-muted-foreground">معلقة</Badge>
                              ),
                      },
                  ]
                : []),
        ],
        [isRebate],
    );

    const paymentColumns = useMemo<ColumnDef<PaymentRow>[]>(
        () => [
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
            { key: 'total', header: 'الإجمالي', cell: (p) => <span className="font-semibold tabular-nums">{formatCurrency(p.totalRebate)}</span> },
            { key: 'paidAt', header: 'تاريخ الدفع', cell: (p) => <span className="tabular-nums" dir="ltr">{p.paidAt}</span> },
        ],
        [],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="space-y-6 p-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">مرحباً، {agent.name}</h1>
                        <p className="text-muted-foreground text-sm">
                            {agent.branchName ?? ''} · {agent.discountModeLabel ?? ''} {agent.rate}
                            {agent.discountType === 'fixed' ? ' ر.س' : '%'}
                        </p>
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="عدد الفواتير" value={String(summary.invoiceCount)} />
                    {isRebate ? (
                        <>
                            <StatCard label="إجمالي العمولة" value={formatCurrency(summary.rebateEarned)} />
                            <StatCard label="العمولة المدفوعة" value={formatCurrency(summary.rebatePaid)} />
                            <StatCard label="العمولة المستحقة" value={formatCurrency(summary.rebateOutstanding)} accent />
                        </>
                    ) : (
                        <StatCard label="إجمالي الخصومات الممنوحة" value={formatCurrency(summary.discountGiven)} />
                    )}
                </div>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">أحدث الفواتير</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <DataTable columns={invoiceColumns} data={recentInvoices} keyExtractor={(i) => `${i.type}-${i.invoiceNumber}`} />
                    </CardContent>
                </Card>

                {isRebate && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">سجل دفعات العمولة</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <DataTable columns={paymentColumns} data={payments} keyExtractor={(p) => `${p.periodStart}-${p.periodEnd}-${p.paidAt}`} />
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
