import { DataTable, type ColumnDef } from '@/components/data-table';
import DateRangeBar from '@/components/reports/date-range-bar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useReportFilters } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { useMemo } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'بوابة المندوب', href: '/agent-portal' }];

/** The terms this agent works on inside one branch — one entry per branch. */
interface BranchTerms {
    branchId: number;
    branchName: string;
    discountMode: 'discount' | 'rebate' | null;
    discountModeLabel: string | null;
    discountType: 'percentage' | 'fixed';
    rate: number;
}

interface AgentInfo {
    name: string;
    branches: BranchTerms[];
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
    /** Which branch raised it — an agent may work with several. */
    branchName: string | null;
    /** Who raised the invoice. */
    employeeName: string | null;
    /** First service/product on the invoice, plus a count of the rest. */
    itemsLabel: string | null;
    totalAmount: number;
    rebate: number;
    lineCommission: number;
    discount: number;
    isRebatePaid: boolean;
    status: string;
    statusLabel: string;
    createdAt: string | null;
}

interface PaymentRow {
    branchName: string | null;
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
    filters: { from: string; to: string; branch: string | null };
    /** Today — the range the portal opens on. */
    defaultDate: string;
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

export default function AgentPortalIndex({ agent, summary, recentInvoices, payments, filters, defaultDate }: Props) {
    // The range and the branch both go through the same hook as the reports so
    // DateRangeBar behaves identically everywhere.
    const f = useReportFilters(
        '/agent-portal',
        { from: filters.from, to: filters.to, branch: filters.branch ?? 'all' },
        { from: defaultDate, to: defaultDate, branch: 'all' },
    );
    // An agent may be on rebate terms in one branch and discount terms in
    // another, so the rebate columns show when any branch pays a rebate.
    const isMultiBranch = agent.branches.length > 1;
    const isRebate = agent.branches.some((b) => b.discountMode === 'rebate');
    const hasDiscount = agent.branches.some((b) => b.discountMode === 'discount');
    // Per-line commissions can accrue to any agent, whatever their invoice-level mode.
    const hasLineCommission = recentInvoices.some((i) => i.lineCommission > 0);

    const invoiceColumns = useMemo<ColumnDef<InvoiceRow>[]>(
        () => [
            {
                key: 'number',
                header: 'رقم الفاتورة',
                cell: (i) => (
                    <span className="tabular-nums" dir="ltr">
                        {i.invoiceNumber}
                    </span>
                ),
            },
            { key: 'type', header: 'النوع', cell: (i) => (i.type === 'service' ? 'خدمة' : 'منتجات') },
            ...(isMultiBranch
                ? [{ key: 'branch', header: 'الفرع', cell: (i: InvoiceRow) => i.branchName ?? '—' }]
                : []),
            {
                key: 'service',
                header: 'الخدمة',
                cell: (i) => <span className="text-muted-foreground">{i.itemsLabel ?? '—'}</span>,
            },
            { key: 'employee', header: 'الموظف', cell: (i) => i.employeeName ?? '—' },
            {
                key: 'date',
                header: 'التاريخ',
                cell: (i) => (
                    <span className="tabular-nums" dir="ltr">
                        {i.createdAt}
                    </span>
                ),
            },
            { key: 'total', header: 'إجمالي الفاتورة', cell: (i) => <span className="tabular-nums">{formatCurrency(i.totalAmount)}</span> },
            {
                key: 'amount',
                header: isRebate ? 'العمولة' : 'الخصم',
                cell: (i) => <span className="font-semibold tabular-nums">{formatCurrency(isRebate ? i.rebate : i.discount)}</span>,
            },
            ...(hasLineCommission
                ? [
                      {
                          key: 'lineCommission',
                          header: 'عمولة البنود',
                          cell: (i: InvoiceRow) => (
                              <span className="font-semibold tabular-nums">{i.lineCommission > 0 ? formatCurrency(i.lineCommission) : '—'}</span>
                          ),
                      },
                  ]
                : []),
            ...(isRebate || hasLineCommission
                ? [
                      {
                          key: 'paid',
                          header: 'حالة العمولة',
                          cell: (i: InvoiceRow) =>
                              i.rebate + i.lineCommission <= 0 ? (
                                  <span className="text-muted-foreground">—</span>
                              ) : i.isRebatePaid ? (
                                  <Badge variant="secondary">مدفوعة</Badge>
                              ) : (
                                  <Badge variant="outline" className="text-muted-foreground">
                                      معلقة
                                  </Badge>
                              ),
                      },
                  ]
                : []),
        ],
        [isRebate, hasLineCommission, isMultiBranch],
    );

    const paymentColumns = useMemo<ColumnDef<PaymentRow>[]>(
        () => [
            ...(isMultiBranch
                ? [{ key: 'branch', header: 'الفرع', cell: (p: PaymentRow) => p.branchName ?? '—' }]
                : []),
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
            {
                key: 'paidAt',
                header: 'تاريخ الدفع',
                cell: (p) => (
                    <span className="tabular-nums" dir="ltr">
                        {p.paidAt}
                    </span>
                ),
            },
        ],
        [isMultiBranch],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="space-y-6 p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold">مرحباً، {agent.name}</h1>
                        {/* Terms differ per branch, so name each branch's own. */}
                        <div className="text-muted-foreground mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm">
                            {agent.branches.map((b) => (
                                <span key={b.branchId}>
                                    {b.branchName} · {b.discountModeLabel ?? ''} {b.rate}
                                    {b.discountType === 'fixed' ? ' ر.س' : '%'}
                                </span>
                            ))}
                        </div>
                    </div>

                    {isMultiBranch && (
                        <div className="w-56">
                            <Select value={f.draft.branch ?? 'all'} onValueChange={(val) => f.replace('branch', val)}>
                                <SelectTrigger aria-label="تصفية بالفرع">
                                    <SelectValue placeholder="كل الفروع" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">كل الفروع</SelectItem>
                                    {agent.branches.map((b) => (
                                        <SelectItem key={b.branchId} value={b.branchId.toString()}>
                                            {b.branchName}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                </div>

                <DateRangeBar filters={f} from={filters.from} to={filters.to} />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="عدد الفواتير" value={String(summary.invoiceCount)} />
                    {/* Mixed terms across branches: show both sides rather than
                        letting one branch's mode hide the other's figures. */}
                    {isRebate && (
                        <>
                            <StatCard label="إجمالي العمولة" value={formatCurrency(summary.rebateEarned)} />
                            <StatCard label="العمولة المدفوعة" value={formatCurrency(summary.rebatePaid)} />
                            <StatCard label="العمولة المستحقة" value={formatCurrency(summary.rebateOutstanding)} accent />
                        </>
                    )}
                    {hasDiscount && (
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
