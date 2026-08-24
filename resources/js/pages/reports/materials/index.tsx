import { DataTable, type ColumnDef } from '@/components/data-table';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import DateRangeBar from '@/components/reports/date-range-bar';
import { FilterSelect } from '@/components/reports/filter-fields';
import { FilterModal } from '@/components/reports/filter-modal';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TableCell, TableRow } from '@/components/ui/table';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate, formatQty } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import {
    type MaterialsReportDayRow,
    type MaterialsReportFilters,
    type MaterialsReportProductRow,
    type MaterialsReportRow,
    type MaterialsReportServiceRow,
    type MaterialsReportTotals,
} from '@/types/materials-report';
import { Head } from '@inertiajs/react';
import { Boxes, Coins, Download, Layers, ReceiptText } from 'lucide-react';
import { useMemo } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'تقرير استهلاك الخامات', href: '/reports/materials' }];

const REPORT_URL = '/reports/materials';

const EMPTY_STATE = <span className="text-muted-foreground">لا توجد حركات خامات مطابقة للتصفية</span>;

const dash = <span className="text-muted-foreground">—</span>;

/** الكمية بوحدتها، بلا أصفار عائدة. */
const qtyWithUnit = (qty: number, unitName: string | null) => (
    <span className="tabular-nums" dir="ltr">
        {formatQty(qty)}
        {unitName ? <span className="text-muted-foreground ms-1">{unitName}</span> : null}
    </span>
);

const productColumns: ColumnDef<MaterialsReportProductRow>[] = [
    { key: 'name', header: 'الخامة', className: 'font-medium', cell: (row) => row.name },
    { key: 'netQty', header: 'الكمية الصافية', cell: (row) => qtyWithUnit(row.netQty, row.unitName) },
    { key: 'netCost', header: 'التكلفة', className: 'font-medium', cell: (row) => formatCurrency(row.netCost) },
];

const serviceColumns: ColumnDef<MaterialsReportServiceRow>[] = [
    { key: 'name', header: 'الخدمة', className: 'font-medium', cell: (row) => row.name },
    { key: 'invoiceCount', header: 'عدد الفواتير', cell: (row) => row.invoiceCount },
    {
        key: 'netQty',
        header: 'الكمية الصافية',
        cell: (row) => (
            <span className="tabular-nums" dir="ltr">
                {formatQty(row.netQty)}
            </span>
        ),
    },
    { key: 'netCost', header: 'التكلفة', className: 'font-medium', cell: (row) => formatCurrency(row.netCost) },
];

const dayColumns: ColumnDef<MaterialsReportDayRow>[] = [
    { key: 'date', header: 'التاريخ', cell: (row) => formatDate(row.date) },
    {
        key: 'netQty',
        header: 'الكمية الصافية',
        cell: (row) => (
            <span className="tabular-nums" dir="ltr">
                {formatQty(row.netQty)}
            </span>
        ),
    },
    { key: 'netCost', header: 'التكلفة', className: 'font-medium', cell: (row) => formatCurrency(row.netCost) },
];

interface Props {
    totals: MaterialsReportTotals;
    byProduct: MaterialsReportProductRow[];
    byService: MaterialsReportServiceRow[];
    byDay: MaterialsReportDayRow[];
    movements: MaterialsReportRow[];
    filters: MaterialsReportFilters;
    /** اليوم — القيمة «المُفرَّغة» لحقلي التاريخ، فالتقرير يفتح على اليوم */
    defaultDate: string;
    branches: { id: number; name: string }[];
    products: { id: number; name: string }[];
    services: { id: number; name: string }[];
    isSuperAdmin: boolean;
}

/**
 * ما سحبته الخدمات المعتمَدة من المخزون وما أعادته المرتجعات إليه.
 *
 * كل الأرقام **صافية**: حركة الإرجاع تُطرح من حركة الصرف، فيوم فيه فاتورة اعتُمدت
 * ثم استُرجعت يقرأ صفراً لا ضِعفاً. والتكلفة من سعر التكلفة المخزَّن على الحركة،
 * فلا يُعيد تعديلُ سعر المنتج اليوم كتابةَ تكلفة الأمس.
 */
export default function MaterialsReportIndex({
    totals,
    byProduct,
    byService,
    byDay,
    movements,
    filters,
    defaultDate,
    branches,
    products,
    services,
    isSuperAdmin,
}: Props) {
    const canPickBranch = isSuperAdmin && branches.length > 0;

    const defaults = useMemo<FilterValues>(
        () => ({ from: defaultDate, to: defaultDate, branch: 'all', product: 'all', service: 'all' }),
        [defaultDate],
    );

    const applied: FilterValues = {
        from: filters.from ?? defaultDate,
        to: filters.to ?? defaultDate,
        branch: filters.branch ?? 'all',
        product: filters.product ?? 'all',
        service: filters.service ?? 'all',
    };
    const f = useReportFilters(REPORT_URL, applied, defaults);

    const qs = new URLSearchParams(f.appliedQuery).toString();
    const exportUrl = `${REPORT_URL}/export${qs ? `?${qs}` : ''}`;

    const detailColumns = useMemo<ColumnDef<MaterialsReportRow>[]>(
        () => [
            { key: 'date', header: 'التاريخ', cell: (row) => formatDate(row.date) },
            {
                key: 'directionLabel',
                header: 'الحركة',
                cell: (row) => <span className={row.direction === 'return_in' ? 'text-emerald-600' : 'text-amber-600'}>{row.directionLabel}</span>,
            },
            { key: 'productName', header: 'الخامة', className: 'font-medium', cell: (row) => row.productName },
            { key: 'qty', header: 'الكمية', cell: (row) => qtyWithUnit(row.qty, row.unitName) },
            { key: 'serviceName', header: 'الخدمة', cell: (row) => row.serviceName ?? dash },
            { key: 'invoiceNumber', header: 'الفاتورة', cell: (row) => row.invoiceNumber ?? dash },
            ...(isSuperAdmin ? [{ key: 'branchName', header: 'الفرع', cell: (row: MaterialsReportRow) => row.branchName ?? dash }] : []),
            { key: 'unitCost', header: 'تكلفة الوحدة', cell: (row) => formatCurrency(row.unitCost) },
            { key: 'cost', header: 'التكلفة', className: 'font-semibold', cell: (row) => formatCurrency(row.cost) },
            { key: 'userName', header: 'المستخدم', cell: (row) => row.userName ?? dash },
        ],
        [isSuperAdmin],
    );

    const chips: FilterChip[] = [];
    if (f.isActive('branch')) {
        const name = branches.find((b) => b.id.toString() === applied.branch)?.name ?? applied.branch;
        chips.push({ key: 'branch', label: `الفرع: ${name}`, onRemove: () => f.remove('branch') });
    }
    if (f.isActive('product')) {
        const name = products.find((p) => p.id.toString() === applied.product)?.name ?? applied.product;
        chips.push({ key: 'product', label: `الخامة: ${name}`, onRemove: () => f.remove('product') });
    }
    if (f.isActive('service')) {
        const name = services.find((s) => s.id.toString() === applied.service)?.name ?? applied.service;
        chips.push({ key: 'service', label: `الخدمة: ${name}`, onRemove: () => f.remove('service') });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="تقرير استهلاك الخامات" />
            <div className="p-4 md:p-6">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-bold md:text-2xl">تقرير استهلاك الخامات</h1>
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
                                label="الخامة"
                                value={f.draft.product}
                                onChange={(v) => f.setField('product', v)}
                                allLabel="كل الخامات"
                                options={products.map((p) => ({ value: p.id.toString(), label: p.name }))}
                            />
                            <FilterSelect
                                label="الخدمة"
                                value={f.draft.service}
                                onChange={(v) => f.setField('service', v)}
                                allLabel="كل الخدمات"
                                options={services.map((s) => ({ value: s.id.toString(), label: s.name }))}
                            />
                        </FilterModal>
                        <Button asChild variant="outline" disabled={movements.length === 0}>
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

                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <SummaryCard
                        icon={<Coins className="size-4" />}
                        label="تكلفة الخامات المستهلكة"
                        value={formatCurrency(totals.netCost)}
                        valueClass="text-amber-600"
                    />
                    <SummaryCard icon={<Boxes className="size-4" />} label="الكمية الصافية" value={formatQty(totals.netQty)} />
                    <SummaryCard icon={<Layers className="size-4" />} label="عدد الخامات" value={totals.productCount.toLocaleString('ar')} />
                    <SummaryCard icon={<ReceiptText className="size-4" />} label="عدد الفواتير" value={totals.invoiceCount.toLocaleString('ar')} />
                </div>

                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>الاستهلاك حسب الخامة</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={productColumns}
                            data={byProduct}
                            keyExtractor={(row) => row.productId}
                            emptyState={EMPTY_STATE}
                            footer={
                                <TableRow>
                                    <TableCell className="font-bold">الإجمالي</TableCell>
                                    <TableCell className="font-bold tabular-nums">{formatQty(totals.netQty)}</TableCell>
                                    <TableCell className="font-bold text-amber-600">{formatCurrency(totals.netCost)}</TableCell>
                                </TableRow>
                            }
                        />
                    </CardContent>
                </Card>

                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>الاستهلاك حسب الخدمة</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={serviceColumns}
                            data={byService}
                            keyExtractor={(row) => row.branchServiceId ?? 0}
                            emptyState={EMPTY_STATE}
                        />
                    </CardContent>
                </Card>

                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle>الاستهلاك اليومي</CardTitle>
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

                <Card>
                    <CardHeader>
                        <CardTitle>تفاصيل الحركات</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={detailColumns}
                            data={movements}
                            keyExtractor={(row) => row.id}
                            emptyState={EMPTY_STATE}
                        />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

function SummaryCard({ icon, label, value, valueClass }: { icon: React.ReactNode; label: string; value: string; valueClass?: string }) {
    return (
        <Card className="min-w-0">
            <CardHeader className="pb-2">
                <CardTitle className="text-muted-foreground flex items-center gap-2 text-sm font-medium">
                    <span className="shrink-0">{icon}</span>
                    <span className="truncate">{label}</span>
                </CardTitle>
            </CardHeader>
            <CardContent>
                <p className={`truncate text-xl font-bold sm:text-2xl ${valueClass ?? ''}`}>{value}</p>
            </CardContent>
        </Card>
    );
}
