import { DataTable, TablePagination, type ColumnDef } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeNumeric } from '@/lib/utils';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { type InvoiceMessageRow } from '@/types/invoice';
import { Head, Link, router } from '@inertiajs/react';
import { Lock, Paperclip } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'الفواتير', href: '/invoices' },
    { title: 'الملاحظات الداخلية', href: '/invoices/messages' },
];

const columns: ColumnDef<InvoiceMessageRow>[] = [
    {
        key: 'invoiceNumber',
        header: 'الفاتورة',
        cell: (row) => (
            <Link href={`/invoices/service/${row.invoiceId}`} className="font-medium hover:underline" dir="ltr">
                {row.invoiceNumber}
            </Link>
        ),
    },
    { key: 'authorName', header: 'الكاتب', cell: (row) => row.authorName },
    {
        key: 'body',
        header: 'الملاحظة',
        cell: (row) => (
            <div className="max-w-xl space-y-1">
                {row.privateTo && (
                    <Badge variant="outline" className="gap-1 border-amber-300 text-amber-800 dark:border-amber-800 dark:text-amber-200">
                        <Lock className="size-3" /> خاصة لـ {row.privateTo}
                    </Badge>
                )}
                {row.body && <p className="line-clamp-3 text-sm whitespace-pre-wrap">{row.body}</p>}
                {row.attachmentsCount > 0 && (
                    <span className="text-muted-foreground inline-flex items-center gap-1 text-xs">
                        <Paperclip className="size-3" /> {row.attachmentsCount} مرفق
                    </span>
                )}
            </div>
        ),
    },
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
];

/** تاسك 209 — ملاحظات المحادثات الداخلية وحدها، الأحدث أولاً، كلٌّ إلى فاتورتها. */
export default function InvoiceMessages({ messages }: { messages: Paginated<InvoiceMessageRow> }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="الملاحظات الداخلية" />
            <div className="p-4 md:p-6">
                <div className="mb-6">
                    <h1 className="text-xl font-bold md:text-2xl">الملاحظات الداخلية</h1>
                    <p className="text-muted-foreground text-sm">كل ملاحظات الفواتير في مكان واحد — اضغط رقم الفاتورة لفتح محادثتها</p>
                </div>

                <DataTable
                    rowOffset={Number(messages.meta.from ?? 1) - 1}
                    columns={columns}
                    data={messages.data}
                    keyExtractor={(row) => row.id}
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
