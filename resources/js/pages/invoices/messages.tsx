import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { FilterBar, type FilterOption } from '@/components/filter-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeNumeric } from '@/lib/utils';
import messagesRoute from '@/routes/invoices/messages';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type InvoiceMessageRow } from '@/types/invoice';
import { Head, Link, router } from '@inertiajs/react';
import { Lock, LockOpen } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'الفواتير', href: '/invoices' },
    { title: 'الملاحظات الداخلية', href: '/invoices/messages' },
];

type Filters = Partial<Record<'from' | 'to' | 'author' | 'read' | 'closed', string>>;

const columns: ColumnDef<InvoiceMessageRow>[] = [
    {
        key: 'invoiceNumber',
        header: 'الفاتورة',
        cell: (row) => (
            <div className="flex items-center gap-2">
                {/* تاسك 219: من هنا يتنقّل بين الملاحظات لا الفواتير. */}
                <Link href={`/invoices/service/${row.invoiceId}?from=messages`} className="font-medium hover:underline" dir="ltr">
                    {row.invoiceNumber}
                </Link>
                {row.unreadCount > 0 && <Badge className="bg-sky-600 text-white">{row.unreadCount} جديدة</Badge>}
                {row.closedAt && (
                    <Badge variant="outline" className="text-muted-foreground gap-1">
                        <Lock className="size-3" /> مغلقة
                    </Badge>
                )}
            </div>
        ),
    },
    { key: 'authorName', header: 'آخر كاتب', cell: (row) => row.authorName },
    {
        key: 'body',
        header: 'آخر ملاحظة',
        cell: (row) => (
            <div className="max-w-xl space-y-1">
                {row.privateTo && (
                    <Badge variant="outline" className="gap-1 border-amber-300 text-amber-800 dark:border-amber-800 dark:text-amber-200">
                        <Lock className="size-3" /> خاصة لـ {row.privateTo}
                    </Badge>
                )}
                {row.body && <p className="line-clamp-3 text-sm whitespace-pre-wrap">{row.body}</p>}
            </div>
        ),
    },
    { key: 'messageCount', header: 'الرسائل', cell: (row) => <span className="tabular-nums">{row.messageCount}</span> },
    {
        key: 'createdAt',
        header: 'التاريخ',
        cell: (row) =>
            row.createdAt && (
                <span className="text-muted-foreground text-xs tabular-nums" dir="ltr">
                    {formatDateTimeNumeric(row.createdAt)}
                </span>
            ),
    },
    {
        key: 'actions',
        header: '',
        cell: (row) =>
            // تاسك 221: المسار والصلاحية نفساهما في شاشة الفاتورة.
            row.canClose && (
                <Button variant="outline" size="sm" onClick={() => router.patch(messagesRoute.toggleClosed.url(row.invoiceId), {}, { preserveScroll: true })}>
                    {row.closedAt ? (
                        <>
                            <LockOpen className="size-4" /> فتح المحادثة
                        </>
                    ) : (
                        <>
                            <Lock className="size-4" /> إغلاق المحادثة
                        </>
                    )}
                </Button>
            ),
    },
];

/** تاسكات 209/220/223 — خيوط الملاحظات الداخلية، صفٌّ لكل فاتورة، الأحدث أولاً. */
export default function InvoiceMessages({ messages, filters, authors }: { messages: Paginated<InvoiceMessageRow>; filters: Filters; authors: FilterOption[] }) {
    const apply = (next: Filters) =>
        router.get(
            messagesRoute.index().url,
            Object.fromEntries(Object.entries({ ...filters, ...next }).filter(([, v]) => v)),
            { preserveState: true, replace: true },
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="الملاحظات الداخلية" />
            <div className="p-4 md:p-6">
                <div className="mb-6">
                    <h1 className="text-xl font-bold md:text-2xl">الملاحظات الداخلية</h1>
                    <p className="text-muted-foreground text-sm">كل ملاحظات الفواتير في مكان واحد — اضغط رقم الفاتورة لفتح محادثتها</p>
                </div>

                <div className="mb-6">
                    <FilterBar
                        filters={[
                            { key: 'author', placeholder: 'الكاتب', options: authors, searchable: true },
                            {
                                key: 'read',
                                placeholder: 'القراءة',
                                options: [
                                    { value: 'unread', label: 'غير مقروءة' },
                                    { value: 'read', label: 'مقروءة' },
                                ],
                            },
                            {
                                key: 'closed',
                                placeholder: 'الحالة',
                                options: [
                                    { value: '0', label: 'مفتوحة' },
                                    { value: '1', label: 'مغلقة' },
                                ],
                            },
                        ]}
                        filterValues={{ author: filters.author ?? '', read: filters.read ?? '', closed: filters.closed ?? '' }}
                        onFilterChange={(key, value) => apply({ [key]: value })}
                        onClearAll={() => router.get(messagesRoute.index().url, {}, { preserveState: true, replace: true })}
                    >
                        <div className="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                            <Input
                                type="date"
                                value={filters.from ?? ''}
                                onChange={(e) => apply({ from: e.target.value })}
                                className="h-9 w-full text-sm sm:w-40"
                                aria-label="من تاريخ"
                            />
                            <span className="text-muted-foreground hidden sm:inline">—</span>
                            <Input
                                type="date"
                                value={filters.to ?? ''}
                                onChange={(e) => apply({ to: e.target.value })}
                                className="h-9 w-full text-sm sm:w-40"
                                aria-label="إلى تاريخ"
                            />
                        </div>
                    </FilterBar>
                </div>

                <DataTable
                    rowOffset={Number(messages.meta.from ?? 1) - 1}
                    columns={columns}
                    data={messages.data}
                    keyExtractor={(row) => row.invoiceId}
                    emptyState={<span className="text-muted-foreground">لا توجد ملاحظات</span>}
                />

                <TablePagination
                    currentPage={messages.meta.current_page}
                    totalPages={messages.meta.last_page}
                    totalItems={messages.meta.total}
                    from={messages.meta.from ?? 0}
                    to={messages.meta.to ?? 0}
                    onPageChange={(page) => router.reload({ data: { page } })}
                />
            </div>
        </AppLayout>
    );
}
