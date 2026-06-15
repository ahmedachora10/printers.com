import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
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
                    <CardContent>
                        {transactions.length === 0 ? (
                            <p className="text-muted-foreground py-8 text-center text-sm">لا توجد حركات بعد</p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="text-right">العميل</TableHead>
                                        <TableHead className="text-right">النوع</TableHead>
                                        <TableHead className="text-center">النقاط</TableHead>
                                        <TableHead className="text-center">الرصيد بعدها</TableHead>
                                        <TableHead className="text-center">التاريخ</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {transactions.map((tx) => (
                                        <TableRow key={tx.id}>
                                            <TableCell>
                                                <div className="font-medium">{tx.customerName ?? '—'}</div>
                                                <div className="text-muted-foreground text-xs">{tx.customerPhone}</div>
                                            </TableCell>
                                            <TableCell>
                                                <Badge variant="secondary">{tx.typeLabel}</Badge>
                                            </TableCell>
                                            <TableCell
                                                className={`text-center font-medium ${tx.points >= 0 ? 'text-green-600' : 'text-destructive'}`}
                                            >
                                                {tx.points >= 0 ? '+' : ''}
                                                {formatNumber(tx.points)}
                                            </TableCell>
                                            <TableCell className="text-center">{formatNumber(tx.balanceAfter)}</TableCell>
                                            <TableCell className="text-muted-foreground text-center text-xs">{tx.createdAt}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
