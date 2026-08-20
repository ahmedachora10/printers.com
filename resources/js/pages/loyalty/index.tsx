import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { formatNumber } from '@/lib/utils';
import loyaltyRoute from '@/routes/loyalty';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Award, Coins, Power, Users } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'برنامج الولاء', href: '/loyalty' }];

const ALL_BRANCHES = 'all';

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

interface LoyaltyConfigSummary {
    active: boolean;
    earningRate: number;
    redemptionRate: number;
    minRedemptionPoints: number;
    expiryMonths: number | null;
}

interface BranchConfigRow extends LoyaltyConfigSummary {
    branchId: number;
    branchName: string;
    outstandingPoints: number;
}

interface Props {
    /** null حين ينظر السوبر أدمن إلى الشبكة كلها — لا إعدادات واحدة عندها */
    config: LoyaltyConfigSummary | null;
    outstandingPoints: number;
    customerCount: number;
    tierDistribution: TierRow[];
    transactions: Paginated<LoyaltyTxRow>;
    branchConfigs: { data: BranchConfigRow[]; meta: Paginated<BranchConfigRow>['meta'] | null };
    /** إجماليات الشبكة لبطاقة الحالة — مستقلة عن صفحة الجدول المعروضة */
    branchSummary: { total: number; active: number } | null;
    branches: { id: number; name: string }[];
    isSuperAdmin: boolean;
    filters: { branch: string | null };
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

const branchConfigColumns: ColumnDef<BranchConfigRow>[] = [
    { key: 'branchName', header: 'الفرع', cell: (row) => <span className="font-medium">{row.branchName}</span> },
    {
        key: 'active',
        header: 'الحالة',
        cell: (row) => (
            <Badge variant={row.active ? 'default' : 'secondary'}>{row.active ? 'مُفعَّل' : 'متوقف'}</Badge>
        ),
    },
    {
        key: 'earningRate',
        header: 'الاكتساب',
        headerClassName: 'text-center',
        className: 'text-center',
        cell: (row) => `${row.earningRate} نقطة / ر.س`,
    },
    {
        key: 'redemptionRate',
        header: 'الاستبدال',
        headerClassName: 'text-center',
        className: 'text-center',
        cell: (row) => `${row.redemptionRate} نقطة / ر.س`,
    },
    {
        key: 'expiryMonths',
        header: 'انتهاء الصلاحية',
        headerClassName: 'text-center',
        className: 'text-center',
        cell: (row) => (row.expiryMonths ? `${row.expiryMonths} شهراً` : 'بلا انتهاء'),
    },
    {
        key: 'outstandingPoints',
        header: 'النقاط القائمة',
        headerClassName: 'text-center',
        className: 'text-center font-medium',
        cell: (row) => formatNumber(row.outstandingPoints),
    },
];

export default function LoyaltyIndex({
    config,
    outstandingPoints,
    customerCount,
    tierDistribution,
    transactions,
    branchConfigs,
    branchSummary,
    branches,
    isSuperAdmin,
    filters,
}: Props) {
    // تبديل الفرع يبدأ من الصفحة الأولى في الجدولين — رقمُ صفحةٍ من فرعٍ آخر
    // لا معنى له هنا.
    function selectBranch(value: string) {
        router.get(
            loyaltyRoute.index().url,
            value === ALL_BRANCHES ? {} : { branch: value },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    // كل جدول يحمل اسم صفحته الخاص، والمعاملات تُبنى كاملةً في كل تنقّل: فلتر
    // الفرع وصفحة الجدول الآخر يبقيان كما هما بدل أن يسقطا من الرابط.
    function goToPage(key: 'page' | 'branchPage', page: number) {
        const params: Record<string, string> = {};

        if (filters.branch) params.branch = filters.branch;
        if (transactions.meta) params.page = String(transactions.meta.current_page);
        if (branchConfigs.meta) params.branchPage = String(branchConfigs.meta.current_page);

        params[key] = String(page);

        router.get(loyaltyRoute.index().url, params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="برنامج الولاء" />

            <div className="space-y-4 p-4">
                {isSuperAdmin && (
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-muted-foreground text-sm">الفرع</span>
                        <Select value={filters.branch ?? ALL_BRANCHES} onValueChange={selectBranch}>
                            <SelectTrigger className="w-56">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL_BRANCHES}>كل الفروع</SelectItem>
                                {branches.map((branch) => (
                                    <SelectItem key={branch.id} value={String(branch.id)}>
                                        {branch.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )}

                {/* Summary cards */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between pb-2">
                            <CardTitle className="text-sm font-medium">حالة البرنامج</CardTitle>
                            <Power className={config?.active ? 'size-4 text-green-600' : 'text-muted-foreground size-4'} />
                        </CardHeader>
                        <CardContent>
                            {config ? (
                                <Badge variant={config.active ? 'default' : 'secondary'}>
                                    {config.active ? 'مُفعَّل' : 'متوقف'}
                                </Badge>
                            ) : (
                                <p className="text-muted-foreground text-sm">
                                    {branchSummary?.active ?? 0} من {branchSummary?.total ?? 0} فروع
                                </p>
                            )}
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
                    {config ? (
                        <>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">معدل الاكتساب</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-lg font-semibold">{config.earningRate} نقطة / ر.س</p>
                                    <p className="text-muted-foreground text-xs">تُحتسب على المبلغ صافياً من الضريبة</p>
                                </CardContent>
                            </Card>
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm font-medium">معدل الاستبدال</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-lg font-semibold">{config.redemptionRate} نقطة / ر.س</p>
                                    <p className="text-muted-foreground text-xs">
                                        الحد الأدنى {formatNumber(config.minRedemptionPoints)} نقطة
                                        {config.expiryMonths ? ` · تنتهي بعد ${config.expiryMonths} شهراً خمولاً` : ''}
                                    </p>
                                </CardContent>
                            </Card>
                        </>
                    ) : (
                        <Card className="sm:col-span-2">
                            <CardHeader className="flex flex-row items-center justify-between pb-2">
                                <CardTitle className="text-sm font-medium">عملاء برنامج الولاء</CardTitle>
                                <Users className="text-muted-foreground size-4" />
                            </CardHeader>
                            <CardContent>
                                <p className="text-2xl font-bold">{formatNumber(customerCount)}</p>
                                <p className="text-muted-foreground text-xs">
                                    المعدلات تُضبط لكل فرع على حدة — انظر الجدول أدناه
                                </p>
                            </CardContent>
                        </Card>
                    )}
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

                {/* إعدادات الفروع — للسوبر أدمن حين ينظر إلى الشبكة كلها */}
                {branchConfigs.meta && (
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">إعدادات الولاء حسب الفرع</CardTitle>
                        </CardHeader>
                        <CardContent className="p-0">
                            <DataTable
                                className="rounded-none bg-transparent shadow-none"
                                columns={branchConfigColumns}
                                data={branchConfigs.data}
                                keyExtractor={(row) => row.branchId}
                                emptyState={<span className="text-muted-foreground text-sm">لا توجد فروع نشطة</span>}
                            />
                            {branchConfigs.meta.total > 0 && (
                                <TablePagination
                                    currentPage={branchConfigs.meta.current_page}
                                    totalPages={branchConfigs.meta.last_page}
                                    totalItems={branchConfigs.meta.total}
                                    pageSize={branchConfigs.meta.per_page}
                                    onPageChange={(page) => goToPage('branchPage', page)}
                                />
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* Transactions log */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="text-base">آخر حركات النقاط</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <DataTable
                            className="rounded-none bg-transparent shadow-none"
                            columns={transactionColumns}
                            data={transactions.data}
                            keyExtractor={(tx) => tx.id}
                            emptyState={<span className="text-muted-foreground text-sm">لا توجد حركات بعد</span>}
                        />
                        {transactions.meta && transactions.meta.total > 0 && (
                            <TablePagination
                                currentPage={transactions.meta.current_page}
                                totalPages={transactions.meta.last_page}
                                totalItems={transactions.meta.total}
                                pageSize={transactions.meta.per_page}
                                onPageChange={(page) => goToPage('page', page)}
                            />
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
