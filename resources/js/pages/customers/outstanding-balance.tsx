import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
                    <CardContent>
                        {report.length === 0 ? (
                            <div className="py-12 text-center">
                                <p className="text-muted-foreground">لا توجد مديونيات مستحقة</p>
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="pb-3 text-start font-medium text-muted-foreground">
                                                العميل
                                            </th>
                                            <th className="pb-3 text-start font-medium text-muted-foreground">
                                                الهاتف
                                            </th>
                                            <th className="pb-3 text-start font-medium text-muted-foreground">
                                                الشركة
                                            </th>
                                            <th className="pb-3 text-start font-medium text-muted-foreground">
                                                المبلغ المستحق
                                            </th>
                                            <th className="pb-3 text-start font-medium text-muted-foreground">
                                                أقدم فاتورة
                                            </th>
                                            <th className="pb-3 text-start font-medium text-muted-foreground">
                                                أيام التأخير
                                            </th>
                                            <th className="pb-3 w-16" />
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                        {report.map((row) => {
                                            const days = daysSince(row.oldestDue);
                                            return (
                                                <tr key={row.id} className="hover:bg-muted/30">
                                                    <td className="py-3 font-medium">{row.fullName}</td>
                                                    <td className="py-3">
                                                        <a
                                                            href={`https://wa.me/${row.phone}`}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="font-mono text-green-700 hover:underline"
                                                            dir="ltr"
                                                        >
                                                            {row.phone}
                                                        </a>
                                                    </td>
                                                    <td className="py-3 text-muted-foreground">
                                                        {row.companyName ?? '—'}
                                                    </td>
                                                    <td className="py-3 font-bold tabular-nums text-destructive" dir="ltr">
                                                        {formatSAR(row.totalDue)}
                                                    </td>
                                                    <td className="py-3 text-muted-foreground">
                                                        {row.oldestDue ? formatDate(row.oldestDue) : '—'}
                                                    </td>
                                                    <td className="py-3">
                                                        {days !== null ? (
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
                                                        ) : '—'}
                                                    </td>
                                                    <td className="py-3">
                                                        <Button variant="ghost" size="sm" asChild>
                                                            <Link href={`/customers/${row.id}`}>
                                                                <Eye className="size-3.5" />
                                                            </Link>
                                                        </Button>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                    <tfoot className="border-t">
                                        <tr>
                                            <td colSpan={3} className="pt-3 font-bold">
                                                الإجمالي
                                            </td>
                                            <td className="pt-3 font-bold tabular-nums text-destructive" dir="ltr">
                                                {formatSAR(totalDue)}
                                            </td>
                                            <td colSpan={3} />
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
