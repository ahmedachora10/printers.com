import { DataTable, type ColumnDef } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TableCell, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatDate } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type OutstandingReportItem } from '@/types/customer';
import { Link } from '@inertiajs/react';
import { Download, Eye } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'العملاء', href: '/customers' },
    { title: 'تقرير المديونيات', href: '/customers/outstanding-balance' },
];

function formatSAR(amount: number) {
    return new Intl.NumberFormat('ar-SA', { style: 'currency', currency: 'SAR' }).format(amount);
}

function daysSince(dateStr: string | null): number | null {
    if (!dateStr) return null;
    return Math.floor((Date.now() - new Date(dateStr).getTime()) / 86_400_000);
}

interface Props {
    report: OutstandingReportItem[];
}

const columns: ColumnDef<OutstandingReportItem>[] = [
    { key: 'fullName', header: 'العميل', className: 'font-medium', cell: (row) => row.fullName },
    {
        key: 'phone',
        header: 'الهاتف',
        cell: (row) => (
            <a
                href={`https://wa.me/${row.phone}`}
                target="_blank"
                rel="noreferrer"
                className="font-mono text-green-700 hover:underline"
                dir="ltr"
            >
                {row.phone}
            </a>
        ),
    },
    { key: 'companyName', header: 'الشركة', className: 'text-muted-foreground', cell: (row) => row.companyName ?? '—' },
    {
        key: 'totalDue',
        header: 'المبلغ المستحق',
        className: 'font-bold tabular-nums text-destructive',
        cell: (row) => (
            <span dir="ltr">{formatSAR(row.totalDue)}</span>
        ),
    },
    { key: 'oldestDue', header: 'أقدم فاتورة', className: 'text-muted-foreground', cell: (row) => (row.oldestDue ? formatDate(row.oldestDue) : '—') },
    {
        key: 'daysLate',
        header: 'أيام التأخير',
        cell: (row) => {
            const days = daysSince(row.oldestDue);
            return days !== null ? (
                <Badge
                    variant="outline"
                    className={
                        days > 90
                            ? 'border-red-200 bg-red-50 text-red-700'
                            : days > 30
                              ? 'border-amber-200 bg-amber-50 text-amber-700'
                              : 'border-border bg-muted/60 text-muted-foreground'
                    }
                >
                    {days} يوم
                </Badge>
            ) : (
                '—'
            );
        },
    },
    {
        key: 'actions',
        header: '',
        headerClassName: 'w-16',
        cell: (row) => (
            <Button variant="ghost" size="sm" asChild>
                <Link href={`/customers/${row.id}`}>
                    <Eye className="size-3.5" />
                </Link>
            </Button>
        ),
    },
];

export default function OutstandingBalance({ report }: Props) {
    const totalDue = report.reduce((sum, r) => sum + r.totalDue, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold">تقرير المديونيات</h1>
                        {report.length > 0 && (
                            <p className="mt-1 text-muted-foreground">
                                {report.length} عميل — إجمالي{' '}
                                <span className="font-semibold text-destructive" dir="ltr">
                                    {formatSAR(totalDue)}
                                </span>
                            </p>
                        )}
                    </div>
                    <Button variant="outline" size="sm" asChild>
                        <a href="/customers/export">
                            <Download className="size-4" />
                            تصدير
                        </a>
                    </Button>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>العملاء ذوو المديونية</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={columns}
                            data={report}
                            keyExtractor={(row) => row.id}
                            emptyState={<span className="text-muted-foreground">لا توجد مديونيات مستحقة</span>}
                            footer={
                                <TableRow>
                                    <TableCell colSpan={3} className="font-bold">
                                        الإجمالي
                                    </TableCell>
                                    <TableCell className="font-bold tabular-nums text-destructive" dir="ltr">
                                        {formatSAR(totalDue)}
                                    </TableCell>
                                    <TableCell colSpan={3} />
                                </TableRow>
                            }
                        />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
