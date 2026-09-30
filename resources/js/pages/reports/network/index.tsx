import { DataTable, type ColumnDef } from '@/components/data-table';
import { ReportExportButton } from '@/components/report-export-button';
import { ActiveFilterChips, type FilterChip } from '@/components/reports/active-filter-chips';
import DateRangeBar from '@/components/reports/date-range-bar';
import { FilterSelect } from '@/components/reports/filter-fields';
import { FilterModal } from '@/components/reports/filter-modal';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TableCell, TableRow } from '@/components/ui/table';
import { useReportFilters, type FilterValues } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDate } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { useMemo } from 'react';

interface Row {
    id: number;
    date: string;
    branchName: string;
    deviceName: string;
    deviceNumber: string;
    cardTypeName: string;
    amount: number;
    approved: boolean;
}

interface GroupRow {
    name: string;
    count: number;
    total: number;
}

interface Option {
    id: number;
    name: string;
}

interface Props {
    rows: Row[];
    total: number;
    byCardType: GroupRow[];
    byDevice: GroupRow[];
    filters: { from: string; to: string; branch: string | null; device: string | null; cardType: string | null };
    defaultDate: string;
    branches: Option[];
    devices: Option[];
    cardTypes: Option[];
    isSuperAdmin: boolean;
}

const REPORT_URL = '/reports/network';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'مبالغ الشبكة', href: REPORT_URL }];

const EMPTY_STATE = <span className="text-muted-foreground">لا توجد مبالغ مطابقة للتصفية</span>;

const groupColumns = (label: string): ColumnDef<GroupRow>[] => [
    { key: 'name', header: label, className: 'font-medium', cell: (row) => <bdi>{row.name}</bdi> },
    { key: 'count', header: 'عدد الإدخالات', cell: (row) => row.count },
    { key: 'total', header: 'الإجمالي', className: 'font-semibold', cell: (row) => formatCurrency(row.total) },
];

/** تاسك 146 — مبالغ أجهزة الشبكة من مطابقة الحسابات، حسب التاريخ والجهاز ونوع البطاقة. */
export default function NetworkReportIndex({ rows, total, byCardType, byDevice, filters, defaultDate, branches, devices, cardTypes, isSuperAdmin }: Props) {
    const defaults = useMemo<FilterValues>(() => ({ from: defaultDate, to: defaultDate, branch: 'all', device: 'all', card_type: 'all' }), [defaultDate]);
    const applied: FilterValues = {
        from: filters.from,
        to: filters.to,
        branch: filters.branch ?? 'all',
        device: filters.device ?? 'all',
        card_type: filters.cardType ?? 'all',
    };
    const f = useReportFilters(REPORT_URL, applied, defaults);
    const qs = new URLSearchParams(f.appliedQuery).toString();

    const detailColumns = useMemo<ColumnDef<Row>[]>(
        () => [
            { key: 'date', header: 'التاريخ', cell: (row) => formatDate(row.date) },
            ...(isSuperAdmin ? [{ key: 'branchName', header: 'الفرع', cell: (row: Row) => row.branchName }] : []),
            { key: 'deviceName', header: 'الجهاز', className: 'font-medium', cell: (row) => row.deviceName },
            { key: 'deviceNumber', header: 'رقم الجهاز', cell: (row) => <bdi className="tabular-nums">{row.deviceNumber}</bdi> },
            { key: 'cardTypeName', header: 'نوع البطاقة', cell: (row) => row.cardTypeName },
            { key: 'amount', header: 'المبلغ', className: 'font-semibold', cell: (row) => formatCurrency(row.amount) },
            {
                key: 'approved',
                header: 'المطابقة',
                cell: (row) =>
                    row.approved ? (
                        <Badge variant="outline">معتمدة</Badge>
                    ) : (
                        <Badge variant="outline" className="border-amber-200 bg-amber-50 text-amber-700">
                            غير معتمدة
                        </Badge>
                    ),
            },
        ],
        [isSuperAdmin],
    );

    const chips: FilterChip[] = [];
    const chip = (key: string, label: string, options: Option[]) => {
        if (!f.isActive(key)) return;
        const name = options.find((o) => String(o.id) === applied[key])?.name ?? applied[key];
        chips.push({ key, label: `${label}: ${name}`, onRemove: () => f.remove(key) });
    };
    chip('branch', 'الفرع', branches);
    chip('device', 'الجهاز', devices);
    chip('card_type', 'نوع البطاقة', cardTypes);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="مبالغ الشبكة" />
            <div className="p-4 md:p-6">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <h1 className="text-xl font-bold md:text-2xl">مبالغ الشبكة</h1>
                    <div className="flex items-center gap-2">
                        <FilterModal open={f.open} onOpenChange={f.onOpenChange} onApply={f.apply} onReset={f.reset} activeCount={f.activeCount}>
                            {isSuperAdmin && branches.length > 0 && (
                                <FilterSelect
                                    label="الفرع"
                                    value={f.draft.branch}
                                    onChange={(v) => f.setField('branch', v)}
                                    allLabel="كل الفروع"
                                    options={branches.map((b) => ({ value: String(b.id), label: b.name }))}
                                />
                            )}
                            <FilterSelect
                                label="الجهاز"
                                value={f.draft.device}
                                onChange={(v) => f.setField('device', v)}
                                allLabel="كل الأجهزة"
                                options={devices.map((d) => ({ value: String(d.id), label: d.name }))}
                            />
                            <FilterSelect
                                label="نوع البطاقة"
                                value={f.draft.card_type}
                                onChange={(v) => f.setField('card_type', v)}
                                allLabel="كل الأنواع"
                                options={cardTypes.map((c) => ({ value: String(c.id), label: c.name }))}
                            />
                        </FilterModal>
                        <ReportExportButton href={`${REPORT_URL}/export${qs ? `?${qs}` : ''}`} disabled={rows.length === 0} />
                    </div>
                </div>

                <div className="mb-6">
                    <DateRangeBar filters={f} from={applied.from} to={applied.to} />
                </div>

                <ActiveFilterChips chips={chips} />

                <Card className="mb-6 max-w-xs">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-muted-foreground text-sm font-medium">إجمالي مبالغ الشبكة</CardTitle>
                    </CardHeader>
                    <CardContent className="text-2xl font-bold">{formatCurrency(total)}</CardContent>
                </Card>

                <div className="mb-6 grid gap-6 lg:grid-cols-2">
                    {[
                        { title: 'حسب نوع البطاقة', label: 'نوع البطاقة', data: byCardType },
                        { title: 'حسب الجهاز', label: 'الجهاز', data: byDevice },
                    ].map((group) => (
                        <Card key={group.title}>
                            <CardHeader>
                                <CardTitle>{group.title}</CardTitle>
                            </CardHeader>
                            <CardContent className="p-0">
                                <DataTable
                                    className="rounded-none bg-transparent shadow-none"
                                    columns={groupColumns(group.label)}
                                    data={group.data}
                                    keyExtractor={(row) => row.name}
                                    emptyState={EMPTY_STATE}
                                    footer={
                                        <TableRow>
                                            <TableCell />
                                            <TableCell className="font-bold">الإجمالي</TableCell>
                                            <TableCell className="font-bold">{rows.length}</TableCell>
                                            <TableCell className="font-bold">{formatCurrency(total)}</TableCell>
                                        </TableRow>
                                    }
                                />
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>التفاصيل</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={detailColumns}
                            data={rows}
                            keyExtractor={(row) => row.id}
                            emptyState={EMPTY_STATE}
                        />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
