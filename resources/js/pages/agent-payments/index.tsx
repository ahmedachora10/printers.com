import PaymentFormModal from '@/components/agent-payments/payment-form-modal';
import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import DateRangeBar from '@/components/reports/date-range-bar';
import SortHeader from '@/components/reports/sort-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type AgentOutstanding, type AgentPaymentRow, type PaginatedAgentPayment } from '@/types/agent-payment';
import { router } from '@inertiajs/react';
import { Receipt, Wallet, X } from 'lucide-react';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'مدفوعات المناديب', href: '/agent-payments' }];

const PAGE_URL = '/agent-payments';

/** «15/08» من YYYY-MM-DD — يوم/شهر يكفيان لوصف المدى فوق البطاقة. */
function shortDate(iso: string): string {
    const [, month, day] = iso.split('-');

    return `${day}/${month}`;
}

/** وصف المدى المطبَّق؛ بلا مدى يُقال «كل الفترات» صراحةً حتى لا يُقرأ الرقم على أنه اليوم. */
function rangeLabel(from: string, to: string): string {
    if (!from && !to) return 'كل الفترات';
    if (from && to) return `${shortDate(from)} — ${shortDate(to)}`;

    return from ? `من ${shortDate(from)}` : `حتى ${shortDate(to)}`;
}

interface Props {
    agents: AgentOutstanding[];
    payments: PaginatedAgentPayment;
    /** إجماليات **المدى المطبَّق** لا الصفحة المعروضة. */
    paymentTotals: { paymentsCount: number; paidTotal: number };
    filters: {
        from?: string | null;
        to?: string | null;
        agent?: string | null;
        sort: string;
        dir: 'asc' | 'desc';
        /** تاسك 232: فلاتر بطاقة «العمولات المستحقة» وحدها. */
        due_from?: string | null;
        due_to?: string | null;
        due_branch?: string | null;
        due_sort?: string | null;
    };
    /** للسوبر أدمن وحده. */
    branches: { id: number; name: string }[] | null;
}

export default function AgentPaymentsIndex({ agents, payments, paymentTotals, filters, branches }: Props) {
    const [payingKey, setPayingKey] = useState<string | null>(null);
    // الصفّ يُقرأ من الخصائص الحيّة: تغيير الفترة داخل النافذة يعيد تحميل المستحق.
    const paying = agents.find((a) => `${a.id}-${a.branchId}` === payingKey) ?? null;

    const applied: FilterValues = {
        from: filters.from ?? '',
        to: filters.to ?? '',
        agent: filters.agent ?? '',
        sort: filters.sort,
        dir: filters.dir,
        due_from: filters.due_from ?? '',
        due_to: filters.due_to ?? '',
        due_branch: filters.due_branch ?? '',
        due_sort: filters.due_sort ?? 'name',
    };

    // الفرز الافتراضي (paid_at تنازلياً) لا يُكتب في الرابط — وإلا حمل كل تنقّل
    // معاملين لا يغيّران شيئاً.
    // فلاتر البطاقة (due_*) في الخطّاف نفسه كي لا يُسقطها التنقّل بفلاتر السجلّ، والعكس.
    const f = useReportFilters(PAGE_URL, applied, {
        from: '',
        to: '',
        agent: '',
        sort: 'paid_at',
        dir: 'desc',
        due_from: '',
        due_to: '',
        due_branch: '',
        due_sort: 'name',
    });

    // مدى معكوس يرفضه الخادم — يُنتظر حتى يستقيم.
    const setDueRange = (from: string, to: string) => {
        if (!from || !to || from <= to) f.replaceMany({ due_from: from, due_to: to });
    };
    const hasDueFilter = !!applied.due_from || !!applied.due_to || !!applied.due_branch || applied.due_sort !== 'name';

    // تاسك 164: قائمة المناديب من صفوف «العمولات المستحقة» — المندوب متعدد الفروع
    // يتكرر فيها بفرعين، فيُدمج بالـid.
    const agentOptions = [...new Map(agents.map((a) => [a.id, a.name]))].map(([id, name]) => ({ value: String(id), label: name }));

    // النقرة الأولى على عمودٍ تفرزه تنازلياً (الأحدث/الأكبر أولاً)، والثانية تقلبه.
    const handleSort = (key: string) =>
        f.replaceMany({ sort: key, dir: applied.sort === key && applied.dir === 'desc' ? 'asc' : 'desc' });

    const hasRange = !!applied.from || !!applied.to;

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
            {
                key: 'invoices',
                header: <SortHeader label="الفواتير" sortKey="total_invoices" sort={applied.sort} dir={applied.dir as 'asc' | 'desc'} onSort={handleSort} />,
                cell: (p) => <span className="tabular-nums">{p.totalInvoices}</span>,
            },
            {
                key: 'rebate',
                header: <SortHeader label="الإجمالي" sortKey="total_rebate" sort={applied.sort} dir={applied.dir as 'asc' | 'desc'} onSort={handleSort} />,
                cell: (p) => <span className="font-semibold tabular-nums">{formatCurrency(p.totalRebate)}</span>,
            },
            { key: 'paidBy', header: 'بواسطة', cell: (p) => p.paidBy ?? '—' },
            {
                key: 'paidAt',
                header: <SortHeader label="التاريخ" sortKey="paid_at" sort={applied.sort} dir={applied.dir as 'asc' | 'desc'} onSort={handleSort} />,
                cell: (p) => (
                    <span className="tabular-nums" dir="ltr">
                        {p.paidAt}
                    </span>
                ),
            },
        ],
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [applied.sort, applied.dir],
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
                        <p className="text-muted-foreground text-xs">
                            {applied.due_from || applied.due_to
                                ? `المستحق ${rangeLabel(applied.due_from, applied.due_to)} — ما استُحقّ في هذه الفترة ولم يُصرف.`
                                : 'رصيد قائم منذ آخر تسوية — لا يتأثر بالمدى التاريخي المطبَّق على سجل الدفعات.'}
                        </p>
                        {/* تاسك 232: فلاتر البطاقة وحدها. */}
                        <div className="flex flex-wrap items-center gap-2 pt-2">
                            <Input
                                type="date"
                                aria-label="المستحق من تاريخ"
                                className="h-9 w-auto sm:h-8"
                                dir="ltr"
                                value={applied.due_from}
                                onChange={(e) => setDueRange(e.target.value, applied.due_to)}
                            />
                            <Input
                                type="date"
                                aria-label="المستحق إلى تاريخ"
                                className="h-9 w-auto sm:h-8"
                                dir="ltr"
                                value={applied.due_to}
                                onChange={(e) => setDueRange(applied.due_from, e.target.value)}
                            />
                            {branches && (
                                <Select value={applied.due_branch || 'all'} onValueChange={(v) => f.replace('due_branch', v === 'all' ? '' : v)}>
                                    <SelectTrigger className="h-9 w-auto min-w-36 sm:h-8">
                                        <SelectValue />
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
                            <Select value={applied.due_sort} onValueChange={(v) => f.replace('due_sort', v)}>
                                <SelectTrigger className="h-9 w-auto min-w-36 sm:h-8">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="name">ترتيب: الاسم</SelectItem>
                                    <SelectItem value="amount">ترتيب: الأعلى مستحقاً</SelectItem>
                                </SelectContent>
                            </Select>
                            {hasDueFilter && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => f.replaceMany({ due_from: '', due_to: '', due_branch: '', due_sort: 'name' })}
                                >
                                    <X className="size-3" /> مسح
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        {agents.length === 0 ? (
                            <p className="text-muted-foreground text-sm">لا يوجد مناديب.</p>
                        ) : (
                            <div className="divide-y">
                                {agents.map((a) => (
                                    <div key={`${a.id}-${a.branchId}`} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3">
                                        <div className="flex min-w-0 flex-wrap items-center gap-2">
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
                                                onClick={() => setPayingKey(`${a.id}-${a.branchId}`)}
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

                {/* Payment history — the only half the date range applies to. */}
                <h2 className="mb-3 text-lg font-semibold">سجل الدفعات</h2>

                <Card className="mb-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-3 rounded-md px-4 py-3.5 sm:px-5">
                    <DateRangeBar filters={f} from={applied.from} to={applied.to} />
                    <div className="flex flex-1 flex-wrap items-center justify-end gap-2">
                        <Select value={applied.agent || 'all'} onValueChange={(v) => f.replace('agent', v === 'all' ? '' : v)}>
                            <SelectTrigger className="h-9 min-w-0 flex-1 sm:h-8 sm:max-w-64">
                                <SelectValue placeholder="كل المناديب" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">كل المناديب</SelectItem>
                                {agentOptions.map((o) => (
                                    <SelectItem key={o.value} value={o.value}>
                                        {o.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {/* حقل type="date" لا يُفرَّغ بالكتابة، فالمسح يحتاج زرّاً. */}
                        {(hasRange || applied.agent) && (
                            <Button type="button" variant="ghost" size="sm" onClick={() => f.replaceMany({ from: '', to: '', agent: '' })}>
                                <X className="size-3" /> مسح التصفية
                            </Button>
                        )}
                    </div>
                </Card>

                <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <SummaryTile
                        icon={<Receipt className="size-4" />}
                        label="عدد الدفعات"
                        value={paymentTotals.paymentsCount.toLocaleString('en-US')}
                        hint={rangeLabel(applied.from, applied.to)}
                    />
                    <SummaryTile
                        icon={<Wallet className="size-4" />}
                        label="إجمالي المدفوع"
                        value={formatCurrency(paymentTotals.paidTotal)}
                        hint={rangeLabel(applied.from, applied.to)}
                    />
                </div>

                <DataTable rowOffset={Number(payments.meta.from ?? 1) - 1} columns={columns} data={payments.data} keyExtractor={(p) => p.id} />
                <TablePagination
                    currentPage={payments.meta.current_page as number}
                    totalPages={payments.meta.last_page as number}
                    totalItems={payments.meta.total as number}
                    from={payments.meta.from as number}
                    to={payments.meta.to as number}
                    // router.reload يحتفظ بمعاملات الرابط الحالية، فالمدى والفرز
                    // لا يضيعان عند تغيير الصفحة.
                    onPageChange={(page) => router.reload({ data: { page } })}
                />
            </div>

            <PaymentFormModal
                key={payingKey ?? 'none'}
                open={!!paying}
                onOpenChange={(open) => !open && setPayingKey(null)}
                agent={paying}
                dueFrom={applied.due_from}
                dueTo={applied.due_to}
                onPeriodChange={setDueRange}
            />
        </AppLayout>
    );
}

function SummaryTile({ icon, label, value, hint }: { icon: React.ReactNode; label: string; value: string; hint: string }) {
    return (
        <Card className="px-4 py-3">
            <div className="text-muted-foreground flex items-center gap-2 text-sm font-medium">
                {icon} {label}
            </div>
            <p className="text-2xl font-bold tabular-nums">{value}</p>
            <p className="text-muted-foreground text-xs">{hint}</p>
        </Card>
    );
}
