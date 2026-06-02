import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency, formatDateTime } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type Invoice } from '@/types/invoice';
import { Head } from '@inertiajs/react';
import { Printer, ReceiptText } from 'lucide-react';

const STATUS_COLORS: Record<string, string> = {
    paid: 'border-green-200 bg-green-50 text-green-700',
    due: 'border-red-200 bg-red-50 text-red-700',
    cancelled: 'border-border bg-muted/60 text-muted-foreground',
};

interface Props {
    invoice: Invoice;
}

function MetaRow({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-center justify-between gap-4 py-1">
            <span className="text-sm text-muted-foreground">{label}</span>
            <span className="text-sm font-medium">{value}</span>
        </div>
    );
}

function TotalRow({ label, value, strong = false }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className={`flex items-center justify-between ${strong ? 'text-base font-bold' : 'text-sm'}`}>
            <span className={strong ? '' : 'text-muted-foreground'}>{label}</span>
            <span className="tabular-nums" dir="ltr">
                {value}
            </span>
        </div>
    );
}

export default function InvoiceShow({ invoice }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'الفواتير', href: '/invoices' },
        { title: invoice.invoiceNumber, href: `/invoices/${invoice.type}/${invoice.id}` },
    ];

    const printBase = `/invoices/${invoice.type}/${invoice.id}/print`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`فاتورة ${invoice.invoiceNumber}`} />

            <div className="p-6">
                <div className="mb-6 flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex items-center gap-3">
                            <h1 className="text-2xl font-bold" dir="ltr">
                                {invoice.invoiceNumber}
                            </h1>
                            <Badge variant="outline" className={STATUS_COLORS[invoice.status]}>
                                {invoice.statusLabel}
                            </Badge>
                            <Badge variant="secondary">{invoice.typeLabel}</Badge>
                        </div>
                        {invoice.createdAt && (
                            <p className="text-sm text-muted-foreground">{formatDateTime(invoice.createdAt)}</p>
                        )}
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <a href={`${printBase}?format=thermal`} target="_blank" rel="noreferrer">
                                <ReceiptText className="size-4" /> إيصال حراري
                            </a>
                        </Button>
                        <Button asChild>
                            <a href={`${printBase}?format=a4`} target="_blank" rel="noreferrer">
                                <Printer className="size-4" /> طباعة A4
                            </a>
                        </Button>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    {/* Line items */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>بنود الفاتورة</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="text-start">الصنف</TableHead>
                                        <TableHead className="text-center">الكمية</TableHead>
                                        <TableHead className="text-center">السعر</TableHead>
                                        <TableHead className="text-center">الخصم</TableHead>
                                        <TableHead className="text-end">الإجمالي</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {invoice.lines.map((line, i) => (
                                        <TableRow key={i}>
                                            <TableCell className="font-medium">
                                                {line.name}
                                                {line.sku && (
                                                    <span className="block text-xs text-muted-foreground" dir="ltr">
                                                        {line.sku}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-center tabular-nums">{line.qty}</TableCell>
                                            <TableCell className="text-center tabular-nums" dir="ltr">
                                                {formatCurrency(line.unitPrice)}
                                            </TableCell>
                                            <TableCell className="text-center tabular-nums">
                                                {line.discountPct > 0 ? `${line.discountPct}%` : '—'}
                                            </TableCell>
                                            <TableCell className="text-end tabular-nums font-medium" dir="ltr">
                                                {formatCurrency(line.subtotal)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>

                            <Separator className="my-4" />

                            <div className="ms-auto max-w-xs space-y-2">
                                <TotalRow label="المجموع الفرعي" value={formatCurrency(invoice.subtotal)} />
                                {invoice.tierDiscountAmount > 0 && (
                                    <TotalRow label="خصم المستوى" value={`−${formatCurrency(invoice.tierDiscountAmount)}`} />
                                )}
                                {invoice.couponDiscount > 0 && (
                                    <TotalRow label="خصم الكوبون" value={`−${formatCurrency(invoice.couponDiscount)}`} />
                                )}
                                {invoice.pointsDiscount > 0 && (
                                    <TotalRow label="خصم النقاط" value={`−${formatCurrency(invoice.pointsDiscount)}`} />
                                )}
                                <TotalRow label={`الضريبة (${invoice.vatPct}%)`} value={formatCurrency(invoice.vatAmount)} />
                                <Separator className="my-1" />
                                <TotalRow label="الإجمالي" value={formatCurrency(invoice.totalAmount)} strong />
                            </div>
                        </CardContent>
                    </Card>

                    {/* Meta */}
                    <Card>
                        <CardHeader>
                            <CardTitle>التفاصيل</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <MetaRow label="العميل" value={invoice.customerName ?? 'عميل نقدي'} />
                            {invoice.customerPhone && (
                                <MetaRow label="الهاتف" value={<span dir="ltr">{invoice.customerPhone}</span>} />
                            )}
                            <MetaRow label="طريقة الدفع" value={invoice.paymentMethod ?? '—'} />
                            {invoice.paidAt && <MetaRow label="تاريخ الدفع" value={formatDateTime(invoice.paidAt)} />}
                            {invoice.employeeCommission !== null && (
                                <MetaRow
                                    label="عمولة الموظف"
                                    value={<span dir="ltr">{formatCurrency(invoice.employeeCommission)}</span>}
                                />
                            )}
                            <Separator className="my-3" />
                            <MetaRow label="الفرع" value={invoice.branch.name ?? '—'} />
                            {invoice.branch.taxNumber && (
                                <MetaRow label="الرقم الضريبي" value={<span dir="ltr">{invoice.branch.taxNumber}</span>} />
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
