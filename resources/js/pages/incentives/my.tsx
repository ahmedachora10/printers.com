import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { SummaryCard } from '@/components/reports/summary-card';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type EmployeeDeduction, type IncentivePlan, type PaginatedEmployeeDeduction, type PaginatedIncentivePlan } from '@/types/incentive';
import { Head, router } from '@inertiajs/react';
import { Banknote, Minus, Scale, Target } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'حوافزي وحسوماتي', href: '/my-incentives' }];

const STATUS_VARIANT: Record<string, 'secondary' | 'default' | 'outline' | 'destructive'> = {
    active: 'secondary',
    achieved: 'default',
    missed: 'destructive',
    paid: 'outline',
};

interface Props {
    plans: PaginatedIncentivePlan;
    deductions: PaginatedEmployeeDeduction;
    /** خطة الشهر الجاري إن وُجدت — تُعرض مفصَّلةً أعلى الصفحة. */
    currentPlan: IncentivePlan | null;
    totals: {
        bonusPaid: number;
        deductions: number;
        monthDeductions: number;
        deductionCount: number;
        net: number;
    };
}

const planColumns: ColumnDef<IncentivePlan>[] = [
    { key: 'period', header: 'الفترة', className: 'font-medium', cell: (p) => <span dir="ltr">{p.periodLabel}</span> },
    { key: 'target', header: 'المستهدف', cell: (p) => formatCurrency(p.targetAmount) },
    {
        key: 'achieved',
        header: 'المحقق',
        cell: (p) => (
            <div className="min-w-28">
                <div className="flex items-center justify-between gap-2 text-sm">
                    <span className="tabular-nums">{formatCurrency(p.achievedAmount)}</span>
                    <span className="text-muted-foreground text-xs tabular-nums">{p.progressPct}%</span>
                </div>
                <ProgressBar pct={p.progressPct} />
            </div>
        ),
    },
    { key: 'bonus', header: 'المكافأة', cell: (p) => formatCurrency(p.bonusAmount) },
    {
        key: 'paid',
        header: 'المصروف',
        cell: (p) =>
            p.paidAmount !== null ? (
                <span className="font-semibold tabular-nums text-green-600">{formatCurrency(p.paidAmount)}</span>
            ) : (
                <span className="text-muted-foreground">—</span>
            ),
    },
    { key: 'status', header: 'الحالة', cell: (p) => <Badge variant={STATUS_VARIANT[p.status] ?? 'secondary'}>{p.statusLabel}</Badge> },
];

const deductionColumns: ColumnDef<EmployeeDeduction>[] = [
    { key: 'deductedAt', header: 'التاريخ', className: 'text-sm', cell: (d) => d.deductedAt ?? '—' },
    { key: 'amount', header: 'القيمة', cell: (d) => <span className="text-destructive font-semibold tabular-nums">{formatCurrency(d.amount)}</span> },
    { key: 'reason', header: 'السبب', cell: (d) => d.reasonText },
    { key: 'deductedBy', header: 'بواسطة', cell: (d) => d.deductedBy ?? '—' },
    { key: 'notes', header: 'ملاحظات', className: 'text-sm text-muted-foreground', cell: (d) => d.notes ?? '—' },
];

export default function MyIncentives({ plans, deductions, currentPlan, totals }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="حوافزي وحسوماتي" />
            <div className="p-4 md:p-6">
                <div className="mb-6">
                    <h1 className="text-xl font-bold md:text-2xl">حوافزي وحسوماتي</h1>
                    <p className="text-muted-foreground text-sm">مستهدفك الشهري وما صُرف لك من مكافآت، وما حُسم عليك بسببه.</p>
                </div>

                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <SummaryCard
                        icon={<Target className="size-4" />}
                        label="مستهدف هذا الشهر"
                        value={currentPlan ? formatCurrency(currentPlan.targetAmount) : '—'}
                        hint={currentPlan ? `المحقق ${formatCurrency(currentPlan.achievedAmount)} — ${currentPlan.progressPct}%` : 'لا توجد خطة لهذا الشهر'}
                    />
                    <SummaryCard
                        icon={<Banknote className="size-4" />}
                        label="إجمالي المكافآت المصروفة"
                        value={formatCurrency(totals.bonusPaid)}
                        valueClass="text-green-600"
                    />
                    <SummaryCard
                        icon={<Minus className="size-4" />}
                        label="إجمالي الخصومات"
                        value={formatCurrency(totals.deductions)}
                        valueClass={totals.deductions > 0 ? 'text-destructive' : undefined}
                        hint={`${totals.deductionCount} حسم — منها ${formatCurrency(totals.monthDeductions)} هذا الشهر`}
                    />
                    <SummaryCard
                        icon={<Scale className="size-4" />}
                        label="الصافي"
                        value={formatCurrency(totals.net)}
                        valueClass={totals.net < 0 ? 'text-destructive' : undefined}
                        hint="المصروف ناقص الخصومات"
                    />
                </div>

                {currentPlan && (
                    <Card className="mb-6">
                        <CardHeader>
                            <CardTitle>خطة الشهر الجاري — {currentPlan.periodLabel}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">نسبة الإنجاز</span>
                                <span className={`font-semibold tabular-nums ${currentPlan.isTargetMet ? 'text-green-600' : ''}`}>{currentPlan.progressPct}%</span>
                            </div>
                            <ProgressBar pct={currentPlan.progressPct} />
                            <div className="grid grid-cols-2 gap-4 pt-1 text-sm sm:grid-cols-4">
                                <Figure label="المحقق" value={formatCurrency(currentPlan.achievedAmount)} />
                                <Figure label="المستهدف" value={formatCurrency(currentPlan.targetAmount)} />
                                <Figure label="المكافأة" value={formatCurrency(currentPlan.bonusAmount)} valueClass="text-green-600" />
                                <Figure label="الحالة" value={currentPlan.statusLabel} />
                            </div>
                            {currentPlan.notes && <p className="text-muted-foreground text-sm">{currentPlan.notes}</p>}
                        </CardContent>
                    </Card>
                )}

                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>خطط الحوافز</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={planColumns}
                            data={plans.data}
                            keyExtractor={(p) => p.id}
                            emptyState={<span className="text-muted-foreground">لا توجد خطط حوافز مسجّلة لك بعد</span>}
                        />
                        <TablePagination
                            currentPage={plans.meta.current_page as number}
                            totalPages={plans.meta.last_page as number}
                            totalItems={plans.meta.total as number}
                            onPageChange={(page) => router.reload({ data: { page } })}
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>الخصومات</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={deductionColumns}
                            data={deductions.data}
                            keyExtractor={(d) => d.id}
                            emptyState={<span className="text-muted-foreground">لا توجد حسومات عليك</span>}
                        />
                        <TablePagination
                            currentPage={deductions.meta.current_page as number}
                            totalPages={deductions.meta.last_page as number}
                            totalItems={deductions.meta.total as number}
                            onPageChange={(page) => router.reload({ data: { deductionsPage: page } })}
                        />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

function Figure({ label, value, valueClass }: { label: string; value: string; valueClass?: string }) {
    return (
        <div>
            <p className="text-muted-foreground text-xs">{label}</p>
            <p className={`font-semibold tabular-nums ${valueClass ?? ''}`}>{value}</p>
        </div>
    );
}

function ProgressBar({ pct }: { pct: number }) {
    return (
        <div className="bg-muted h-1.5 w-full overflow-hidden rounded-full">
            <div className={`h-full rounded-full ${pct >= 100 ? 'bg-green-600' : 'bg-primary'}`} style={{ width: `${Math.min(100, pct)}%` }} />
        </div>
    );
}
