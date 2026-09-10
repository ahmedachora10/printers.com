import { DataTable, type ColumnDef } from '@/components/data-table';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import DateRangeBar from '@/components/reports/date-range-bar';
import { FilterSelect } from '@/components/reports/filter-fields';
import { FilterModal } from '@/components/reports/filter-modal';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TableCell, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import {
    type SalesReportBranchRow,
    type SalesReportDayRow,
    type SalesReportEmployeeRow,
    type SalesReportFilters,
    type SalesReportPaymentMethodRow,
    type SalesReportTotals,
    type SalesReportTypeRow,
} from '@/types/sales-report';
import { Head } from '@inertiajs/react';
import { Bike, CreditCard, Download, Info, Percent, PiggyBank, Receipt, TrendingUp, Undo2, Wallet } from 'lucide-react';
import { useMemo } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'تقرير المبيعات', href: '/reports/sales' }];

const REPORT_URL = '/reports/sales';

const TYPE_LABELS: Record<string, string> = { product: 'منتجات', service: 'خدمات' };

const EMPTY_STATE = <span className="text-muted-foreground">لا توجد بيانات مطابقة للتصفية</span>;

const typeColumns: ColumnDef<SalesReportTypeRow>[] = [
    { key: 'label', header: 'النوع', className: 'font-medium', cell: (row) => row.label },
    { key: 'count', header: 'عدد الفواتير', cell: (row) => row.count },
    { key: 'subtotal', header: 'قبل الخصم', cell: (row) => formatCurrency(row.subtotal) },
    { key: 'discounts', header: 'الخصومات', className: 'text-amber-600', cell: (row) => formatCurrency(row.discounts) },
    { key: 'vat', header: 'الضريبة', className: 'text-muted-foreground', cell: (row) => formatCurrency(row.vat) },
    // تاسك 93: الشحن عمودٌ مستقلّ حتى لا يُقرأ إيراد خدمات.
    { key: 'shipping', header: 'التوصيل', className: 'text-muted-foreground', cell: (row) => formatCurrency(row.shipping) },
    { key: 'refunds', header: 'المرتجعات', className: 'text-rose-600', cell: (row) => formatCurrency(row.refunds) },
    { key: 'total', header: 'الإجمالي', className: 'font-semibold text-green-600', cell: (row) => formatCurrency(row.total) },
];

const breakdownColumns = (nameHeader: string): ColumnDef<BreakdownRow>[] => [
    { key: 'name', header: nameHeader, className: 'font-medium', cell: (row) => row.name },
    { key: 'count', header: 'عدد الفواتير', cell: (row) => row.count },
    { key: 'total', header: 'الإجمالي', className: 'font-medium', cell: (row) => formatCurrency(row.total) },
];

/**
 * تاسك 97 — المصروفات تُدفع من الدرج، فتُطرح من **النقد وحده** لا من كل
 * المحصَّل. والرقم تدفّقٌ نقدي لا ربحٌ محاسبي: لا يطرح الخامات ولا العمولات.
 */
const CASH_HINT = 'المحصَّل نقداً ناقص المصروفات المسجّلة — لا تُطرح المصروفات من الشبكة ولا التحويل. ليس ربحاً صافياً.';

/** رأس عمودٍ يحمل تفسيره في tooltip. */
function HintedHeader({ label, hint }: { label: string; hint: string }) {
    return (
        <TooltipProvider delayDuration={100}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <span className="inline-flex cursor-help items-center gap-1">
                        {label}
                        <Info className="text-muted-foreground size-3.5" aria-hidden />
                        <span className="sr-only">{hint}</span>
                    </span>
                </TooltipTrigger>
                <TooltipContent className="max-w-xs">{hint}</TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

function Remaining({ value }: { value: number }) {
    return <span className={value < 0 ? 'text-rose-600' : 'text-green-600'}>{formatCurrency(value)}</span>;
}

const dayColumns: ColumnDef<SalesReportDayRow>[] = [
    { key: 'date', header: 'التاريخ', cell: (row) => formatDate(row.date) },
    { key: 'count', header: 'عدد الفواتير', cell: (row) => row.count },
    { key: 'total', header: 'الإجمالي', className: 'font-medium', cell: (row) => formatCurrency(row.total) },
    { key: 'cash', header: 'منها نقداً', cell: (row) => formatCurrency(row.cash) },
    {
        key: 'expenses',
        header: 'المصروفات',
        className: 'text-amber-600',
        cell: (row) => formatCurrency(row.expenses),
    },
    {
        key: 'cashRemaining',
        header: <HintedHeader label="المتبقي من النقد" hint={CASH_HINT} />,
        className: 'font-semibold',
        cell: (row) => <Remaining value={row.cashRemaining} />,
    },
];

interface Props {
    totals: SalesReportTotals;
    byType: SalesReportTypeRow[];
    byDay: SalesReportDayRow[];
    byEmployee: SalesReportEmployeeRow[];
    byPaymentMethod: SalesReportPaymentMethodRow[];
    byBranch: SalesReportBranchRow[];
    filters: SalesReportFilters;
    /** Today — the date fields' cleared value, since the report opens on today. */
    defaultDate: string;
    branches: { id: number; name: string }[];
    isSuperAdmin: boolean;
}

export default function SalesReportIndex({
    totals,
    byType,
    byDay,
    byEmployee,
    byPaymentMethod,
    byBranch,
    filters,
    defaultDate,
    branches,
    isSuperAdmin,
}: Props) {
    const canPickBranch = isSuperAdmin && branches.length > 0;

    // Today is the cleared state of the date fields, so an untouched report shows
    // no date chips and clearing one snaps that end back to today.
    const defaults = useMemo<FilterValues>(
        () => ({ from: defaultDate, to: defaultDate, branch: 'all', type: 'all' }),
        [defaultDate],
    );

    const applied: FilterValues = {
        from: filters.from ?? defaultDate,
        to: filters.to ?? defaultDate,
        branch: filters.branch ?? 'all',
        type: filters.type ?? 'all',
    };
    const f = useReportFilters(REPORT_URL, applied, defaults);

    const qs = new URLSearchParams(f.appliedQuery).toString();
    const exportUrl = `${REPORT_URL}/export${qs ? `?${qs}` : ''}`;

    // No chips for from/to — the range is always visible in the bar above.
    const chips: FilterChip[] = [];
    if (f.isActive('branch')) {
        const name = branches.find((b) => b.id.toString() === applied.branch)?.name ?? applied.branch;
        chips.push({ key: 'branch', label: `الفرع: ${name}`, onRemove: () => f.remove('branch') });
    }
    if (f.isActive('type'))
        chips.push({ key: 'type', label: `النوع: ${TYPE_LABELS[applied.type] ?? applied.type}`, onRemove: () => f.remove('type') });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="تقرير المبيعات" />
            <div className="p-4 md:p-6">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-bold md:text-2xl">تقرير المبيعات</h1>
                    <div className="flex items-center gap-2">
                        <FilterModal open={f.open} onOpenChange={f.onOpenChange} onApply={f.apply} onReset={f.reset} activeCount={f.activeCount}>
                            {canPickBranch && (
                                <FilterSelect
                                    label="الفرع"
                                    value={f.draft.branch}
                                    onChange={(v) => f.setField('branch', v)}
                                    allLabel="كل الفروع"
                                    options={branches.map((b) => ({ value: b.id.toString(), label: b.name }))}
                                />
                            )}
                            <FilterSelect
                                label="النوع"
                                value={f.draft.type}
                                onChange={(v) => f.setField('type', v)}
                                options={[
                                    { value: 'product', label: 'منتجات' },
                                    { value: 'service', label: 'خدمات' },
                                ]}
                            />
                        </FilterModal>
                        <Button asChild variant="outline" disabled={totals.invoiceCount === 0}>
                            <a href={exportUrl}>
                                <Download className="size-4" /> تصدير Excel
                            </a>
                        </Button>
                    </div>
                </div>

                <div className="mb-6">
                    <DateRangeBar filters={f} from={applied.from} to={applied.to} />
                </div>

                <ActiveFilterChips chips={chips} />

                {/* Summary tiles */}
                {/* Five tracks would squeeze the currency figures at lg, where the
                    content column is only ~712px wide beside the sidebar. */}
                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                    <SummaryCard icon={<Receipt className="size-4" />} label="عدد الفواتير" value={totals.invoiceCount.toLocaleString('ar')} />
                    <SummaryCard icon={<TrendingUp className="size-4" />} label="قبل الخصم" value={formatCurrency(totals.subtotal)} />
                    <SummaryCard
                        icon={<Percent className="size-4" />}
                        label="الخصومات"
                        value={formatCurrency(totals.discounts)}
                        valueClass="text-amber-600"
                    />
                    <SummaryCard
                        icon={<CreditCard className="size-4" />}
                        label="الضريبة"
                        value={formatCurrency(totals.vat)}
                        valueClass="text-muted-foreground"
                    />
                    {/* تاسك 93: بطاقةٌ لا تظهر إلا لفرعٍ يوصّل — الفرع بلا توصيل
                        لا تُزحم شبكتُه ببطاقةٍ صفرها ثابت. */}
                    {totals.shipping > 0 && (
                        <SummaryCard
                            icon={<Bike className="size-4" />}
                            label="جملة التوصيل"
                            value={formatCurrency(totals.shipping)}
                            valueClass="text-muted-foreground"
                        />
                    )}
                    {/* بطاقةٌ لا تظهر إلا عند وجود مرتجعات، فتبقى الشبكة خمس
                        بطاقات في الحالة الغالبة ولا تُضغط أرقام العملة. */}
                    {totals.refunds > 0 && (
                        <SummaryCard
                            icon={<Undo2 className="size-4" />}
                            label="المرتجعات"
                            value={formatCurrency(totals.refunds)}
                            valueClass="text-rose-600"
                        />
                    )}
                    <SummaryCard
                        icon={<Wallet className="size-4" />}
                        label="صافي المبيعات"
                        value={formatCurrency(totals.total)}
                        valueClass="text-green-600"
                    />
                    {/* تاسك 87/97: بطاقتان تقابلان عمودَي المصروفات والمتبقي من
                        النقد، فلا يقرأ المستخدم رقماً في الجدول لا يجد جملته أعلاه. */}
                    <SummaryCard
                        icon={<Receipt className="size-4" />}
                        label="المصروفات"
                        value={formatCurrency(totals.expenses)}
                        valueClass="text-amber-600"
                    />
                    <SummaryCard
                        icon={<PiggyBank className="size-4" />}
                        label="متبقي النقد"
                        value={formatCurrency(totals.cashRemaining)}
                        valueClass={totals.cashRemaining < 0 ? 'text-rose-600' : 'text-green-600'}
                        hint={CASH_HINT}
                    />
                </div>

                {/* By type */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>المبيعات حسب النوع</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={typeColumns}
                            data={byType}
                            keyExtractor={(row) => row.type}
                            emptyState={EMPTY_STATE}
                            footer={
                                <TableRow>
                                    <TableCell className="font-bold">الإجمالي</TableCell>
                                    <TableCell className="font-bold">{totals.invoiceCount}</TableCell>
                                    <TableCell className="font-bold">{formatCurrency(totals.subtotal)}</TableCell>
                                    <TableCell className="font-bold text-amber-600">{formatCurrency(totals.discounts)}</TableCell>
                                    <TableCell className="text-muted-foreground font-bold">{formatCurrency(totals.vat)}</TableCell>
                                    <TableCell className="text-muted-foreground font-bold">{formatCurrency(totals.shipping)}</TableCell>
                                    <TableCell className="font-bold text-rose-600">{formatCurrency(totals.refunds)}</TableCell>
                                    <TableCell className="font-bold text-green-600">{formatCurrency(totals.total)}</TableCell>
                                </TableRow>
                            }
                        />
                    </CardContent>
                </Card>

                {isSuperAdmin && (
                    <BreakdownCard
                        title="المبيعات حسب الفرع"
                        nameHeader="الفرع"
                        rows={byBranch.map((b) => ({ key: b.branchId, name: b.branchName, count: b.count, total: b.total }))}
                    />
                )}

                <BreakdownCard
                    title="المبيعات حسب الموظف"
                    nameHeader="الموظف"
                    rows={byEmployee.map((e) => ({ key: e.userId, name: e.userName, count: e.count, total: e.total }))}
                />

                <PaymentMethodCard rows={byPaymentMethod} totals={totals} />

                {/* By day */}
                <Card>
                    <CardHeader>
                        <CardTitle>المبيعات اليومية</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={dayColumns}
                            data={byDay}
                            keyExtractor={(row) => row.date}
                            emptyState={EMPTY_STATE}
                        />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

function SummaryCard({
    icon,
    label,
    value,
    valueClass,
    hint,
}: {
    icon: React.ReactNode;
    label: string;
    value: string;
    valueClass?: string;
    /** تفسيرٌ يظهر عند المرور على البطاقة — لرقمٍ يُقرأ خطأً بلا شرح */
    hint?: string;
}) {
    return (
        <Card className="min-w-0">
            <CardHeader className="pb-2">
                <CardTitle className="text-muted-foreground flex items-center gap-2 text-sm font-medium">
                    <span className="shrink-0">{icon}</span>
                    <span className="truncate">{label}</span>
                    {hint && (
                        <TooltipProvider delayDuration={100}>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span className="shrink-0 cursor-help">
                                        <Info className="size-3.5" aria-hidden />
                                        <span className="sr-only">{hint}</span>
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent className="max-w-xs">{hint}</TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    )}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className={`truncate text-xl font-bold sm:text-2xl ${valueClass ?? ''}`}>{value}</p>
            </CardContent>
        </Card>
    );
}

/**
 * تاسك 97 — «المبيعات حسب طريقة الدفع» مع عمودَي المصروفات والمتبقي من النقد.
 * الرقمان على صفّ النقد وحده؛ فإن تعدّدت صفوف النقد (سوبر أدمن عبر فروع لكلٍّ
 * طريقته) فهما في صفّ الإجمالي وحده، إذ لا يُعرف أيّ درجٍ دفع أيّ مصروف.
 */
function PaymentMethodCard({ rows, totals }: { rows: SalesReportPaymentMethodRow[]; totals: SalesReportTotals }) {
    const cashRows = rows.filter((r) => r.isCash).length;
    const onRow = (row: SalesReportPaymentMethodRow) => row.isCash && cashRows === 1;
    const total = rows.reduce((sum, r) => sum + r.total, 0);

    const columns: ColumnDef<SalesReportPaymentMethodRow>[] = [
        { key: 'name', header: 'طريقة الدفع', className: 'font-medium', cell: (row) => row.methodName },
        { key: 'count', header: 'عدد الفواتير', cell: (row) => row.count },
        { key: 'total', header: 'الإجمالي', className: 'font-medium', cell: (row) => formatCurrency(row.total) },
        {
            key: 'expenses',
            header: 'المصروفات',
            className: 'text-amber-600',
            cell: (row) => (onRow(row) ? formatCurrency(totals.expenses) : '—'),
        },
        {
            key: 'cashRemaining',
            header: <HintedHeader label="المتبقي من النقد" hint={CASH_HINT} />,
            className: 'font-semibold',
            cell: (row) => (onRow(row) ? <Remaining value={totals.cashRemaining} /> : '—'),
        },
    ];

    return (
        <Card className="mb-6">
            <CardHeader>
                <CardTitle>المبيعات حسب طريقة الدفع</CardTitle>
            </CardHeader>
            <CardContent className="p-0">
                {cashRows === 0 && totals.expenses > 0 && (
                    <div className="flex items-start gap-2 border-b border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-300">
                        <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
                        <span>لا تحصيل بطريقة دفع معلَّمة «نقدية» في هذه الفترة، فالمصروفات مطروحةٌ من صفر. تُعلَّم طريقة النقد من إعدادات طرق الدفع.</span>
                    </div>
                )}
                <DataTable
                    className="rounded-none bg-transparent shadow-none"
                    columns={columns}
                    data={rows}
                    keyExtractor={(row) => row.methodId ?? 0}
                    emptyState={EMPTY_STATE}
                    footer={
                        <TableRow>
                            <TableCell className="font-bold">الإجمالي</TableCell>
                            <TableCell />
                            <TableCell className="font-bold text-green-600">{formatCurrency(total)}</TableCell>
                            <TableCell className="font-bold text-amber-600">{formatCurrency(totals.expenses)}</TableCell>
                            <TableCell className="font-bold">
                                <Remaining value={totals.cashRemaining} />
                            </TableCell>
                        </TableRow>
                    }
                />
            </CardContent>
        </Card>
    );
}

interface BreakdownRow {
    key: number;
    name: string;
    count: number;
    total: number;
}

function BreakdownCard({ title, nameHeader, rows }: { title: string; nameHeader: string; rows: BreakdownRow[] }) {
    const total = rows.reduce((sum, r) => sum + r.total, 0);

    return (
        <Card className="mb-6">
            <CardHeader>
                <CardTitle>{title}</CardTitle>
            </CardHeader>
            <CardContent className="p-0">
                <DataTable
                    className="rounded-none bg-transparent shadow-none"
                    columns={breakdownColumns(nameHeader)}
                    data={rows}
                    keyExtractor={(row) => row.key}
                    emptyState={EMPTY_STATE}
                    footer={
                        <TableRow>
                            <TableCell className="font-bold">الإجمالي</TableCell>
                            <TableCell />
                            <TableCell className="font-bold text-green-600">{formatCurrency(total)}</TableCell>
                        </TableRow>
                    }
                />
            </CardContent>
        </Card>
    );
}
