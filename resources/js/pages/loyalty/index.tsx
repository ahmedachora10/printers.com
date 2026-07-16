import { DataTable, type ColumnDef } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Award, Coins, Power } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'برنامج الولاء', href: '/loyalty' }];

interface TierRow {
    tier: string;
    label: string;
    count: number;
}

interface LoyaltyTxRow {
    id: number;
    customerName: string | null;
    customerPhone: string | null;
    type: string;
    typeLabel: string;
    points: number;
    balanceAfter: number;
    createdAt: string | null;
}

interface Props {
    active: boolean;
    earningRate: number;
    redemptionRate: number;
    minRedemptionPoints: number;
    outstandingPoints: number;
    tierDistribution: TierRow[];
    transactions: LoyaltyTxRow[];
}

const transactionColumns: ColumnDef<LoyaltyTxRow>[] = [
    {
        key: 'customer',
        header: 'العميل',
        cell: (tx) => (
            <div>
                <div className="font-medium">{tx.customerName ?? '—'}</div>
                <div className="text-muted-foreground text-xs">{tx.customerPhone}</div>
            </div>
        ),
    },
    { key: 'type', header: 'النوع', cell: (tx) => <Badge variant="secondary">{tx.typeLabel}</Badge> },
    {
        key: 'points',
        header: 'النقاط',
        headerClassName: 'text-center',
        cell: (tx) => (
            <span className={`text-center font-medium ${tx.points >= 0 ? 'text-green-600' : 'text-destructive'}`}>
                {tx.points >= 0 ? '+' : ''}
                {formatNumber(tx.points)}
            </span>
        ),
        className: 'text-center',
    },
    { key: 'balanceAfter', header: 'الرصيد بعدها', headerClassName: 'text-center', className: 'text-center', cell: (tx) => formatNumber(tx.balanceAfter) },
    { key: 'createdAt', header: 'التاريخ', headerClassName: 'text-center', className: 'text-muted-foreground text-center text-xs', cell: (tx) => tx.createdAt },
];

export default function LoyaltyIndex({
    active,
    earningRate,
    redemptionRate,
    minRedemptionPoints,
    outstandingPoints,
    tierDistribution,
    transactions,
}: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="برنامج الولاء" />

            <div className="space-y-4 p-4">
                {/* Summary cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">حالة البرنامج</CardTitle>
                            <Power className={active ? 'size-4 text-green-600' : 'text-muted-foreground size-4'} />
                        </CardHeader>
                        <CardContent>
                            <Badge variant={active ? 'default' : 'secondary'}>{active ? 'مُفعَّل' : 'متوقف'}</Badge>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">إجمالي النقاط القائمة</CardTitle>
                            <Coins className="text-muted-foreground size-4" />
                        </CardHeader>
                        <CardContent>
                            <p className="text-2xl font-bold">{formatNumber(outstandingPoints)}</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">معدل الاكتساب</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-lg font-semibold">{earningRate} نقطة / ر.س</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-sm font-medium">معدل الاستبدال</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-lg font-semibold">{redemptionRate} نقطة / ر.س</p>
                            <p className="text-muted-foreground text-xs">الحد الأدنى {formatNumber(minRedemptionPoints)} نقطة</p>
                        </CardContent>
                    </Card>
                </div>

                {/* Tier distribution */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Award className="size-4" /> توزيع الفئات
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 sm:grid-cols-4">
                            {tierDistribution.map((t) => (
                                <div key={t.tier} className="rounded-md border p-3 text-center">
                                    <p className="text-muted-foreground text-sm">{t.label}</p>
                                    <p className="text-xl font-bold">{formatNumber(t.count)}</p>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>

                {/* Transactions log */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">آخر حركات النقاط</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={transactionColumns}
                            data={transactions}
                            keyExtractor={(tx) => tx.id}
                            emptyState={<span className="text-muted-foreground text-sm">لا توجد حركات بعد</span>}
                        />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
