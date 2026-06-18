import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Toaster } from '@/components/ui/sonner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/utils';
import serviceInvoice from '@/routes/invoices/service';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { CheckCircle2, ClipboardList, Paperclip, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

interface ReviewLine {
    name: string;
    qty: number;
    unitPrice: number;
    discountPct: number;
    subtotal: number;
}

interface ReviewInvoice {
    id: number;
    invoiceNumber: string;
    createdAt: string | null;
    employeeName: string | null;
    customerName: string | null;
    customerPhone: string | null;
    branchName: string | null;
    paymentMethod: string | null;
    receiptUrl: string | null;
    subtotal: number;
    vatAmount: number;
    totalAmount: number;
    lines: ReviewLine[];
}

interface Props {
    invoices: ReviewInvoice[];
    isSuperAdmin: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'مراجعة الفواتير الآجلة', href: serviceInvoice.review().url }];

const formatDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('en-GB') : '—');

export default function InvoiceReview({ invoices, isSuperAdmin }: Props) {
    const { props } = usePage<SharedData>();
    const [paying, setPaying] = useState<ReviewInvoice | null>(null);
    const [cancelling, setCancelling] = useState<ReviewInvoice | null>(null);
    const [reason, setReason] = useState('');
    const [reasonError, setReasonError] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [uploadingId, setUploadingId] = useState<number | null>(null);

    useEffect(() => {
        if (props.success) {
            toast.success(props.success as string);
        }
    }, [props.success]);

    function confirmPay() {
        if (!paying) return;
        setSubmitting(true);
        router.patch(
            serviceInvoice.pay(paying.id).url,
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setSubmitting(false);
                    setPaying(null);
                },
            },
        );
    }

    function uploadReceipt(invoiceId: number, file: File | undefined) {
        if (!file) return;
        setUploadingId(invoiceId);
        router.post(
            serviceInvoice.receipt(invoiceId).url,
            { receipt: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (e) => toast.error(e.receipt ?? 'تعذّر رفع الإيصال.'),
                onFinish: () => setUploadingId(null),
            },
        );
    }

    function confirmCancel() {
        if (!cancelling) return;
        if (reason.trim().length < 3) {
            setReasonError('يجب ذكر سبب الإلغاء.');
            return;
        }
        setSubmitting(true);
        router.patch(
            serviceInvoice.cancel(cancelling.id).url,
            { reason: reason.trim() },
            {
                preserveScroll: true,
                onError: (e) => setReasonError(e.reason ?? 'تعذّر إلغاء الفاتورة.'),
                onSuccess: () => {
                    setCancelling(null);
                    setReason('');
                    setReasonError(null);
                },
                onFinish: () => setSubmitting(false),
            },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="مراجعة الفواتير الآجلة" />
            <Toaster position="top-center" richColors />

            <div className="space-y-4 p-4">
                <div className="flex items-center gap-2">
                    <ClipboardList className="size-5" />
                    <h1 className="text-lg font-semibold">الفواتير الآجلة بانتظار المراجعة</h1>
                    <Badge variant="secondary">{invoices.length}</Badge>
                </div>

                {invoices.length === 0 ? (
                    <Card>
                        <CardContent className="text-muted-foreground py-12 text-center text-sm">لا توجد فواتير آجلة بحاجة للمراجعة.</CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {invoices.map((invoice) => (
                            <Card key={invoice.id}>
                                <CardHeader className="pb-3">
                                    <div className="flex items-start justify-between gap-2">
                                        <CardTitle className="text-base">{invoice.invoiceNumber}</CardTitle>
                                        <Badge variant="outline">{formatDate(invoice.createdAt)}</Badge>
                                    </div>
                                    <div className="text-muted-foreground space-y-0.5 text-xs">
                                        {isSuperAdmin && invoice.branchName && <p>الفرع: {invoice.branchName}</p>}
                                        <p>الموظف: {invoice.employeeName ?? '—'}</p>
                                        <p>
                                            العميل: {invoice.customerName ?? 'عميل عابر'}
                                            {invoice.customerPhone ? ` — ${invoice.customerPhone}` : ''}
                                        </p>
                                    </div>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead className="text-right">الخدمة</TableHead>
                                                <TableHead className="text-center">الكمية</TableHead>
                                                <TableHead className="text-center">السعر</TableHead>
                                                <TableHead className="text-left">الإجمالي</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {invoice.lines.map((line, i) => (
                                                <TableRow key={i}>
                                                    <TableCell className="text-right">{line.name}</TableCell>
                                                    <TableCell className="text-center">{line.qty}</TableCell>
                                                    <TableCell className="text-center">{formatCurrency(line.unitPrice)}</TableCell>
                                                    <TableCell className="text-left">{formatCurrency(line.subtotal)}</TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>

                                    <Separator />

                                    <div className="space-y-1 text-sm">
                                        <div className="text-muted-foreground flex justify-between">
                                            <span>المجموع الفرعي</span>
                                            <span>{formatCurrency(invoice.subtotal)}</span>
                                        </div>
                                        <div className="text-muted-foreground flex justify-between">
                                            <span>الضريبة</span>
                                            <span>{formatCurrency(invoice.vatAmount)}</span>
                                        </div>
                                        <div className="flex justify-between font-bold">
                                            <span>الإجمالي</span>
                                            <span>{formatCurrency(invoice.totalAmount)}</span>
                                        </div>
                                    </div>

                                    <div className="space-y-2 rounded-md border bg-muted/40 p-3 text-sm">
                                        <div className="text-muted-foreground flex items-center justify-between">
                                            <span>طريقة الدفع</span>
                                            <span className="font-medium">{invoice.paymentMethod ?? '—'}</span>
                                        </div>
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="text-muted-foreground flex items-center gap-1.5">
                                                <Paperclip className="size-4" /> إيصال التحويل
                                            </span>
                                            {invoice.receiptUrl ? (
                                                <a
                                                    href={invoice.receiptUrl}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-primary font-medium hover:underline"
                                                >
                                                    عرض الإيصال
                                                </a>
                                            ) : (
                                                <span className="text-amber-600 dark:text-amber-400">لا يوجد</span>
                                            )}
                                        </div>
                                        <Label
                                            htmlFor={`receipt-${invoice.id}`}
                                            className="text-primary block cursor-pointer text-xs hover:underline"
                                        >
                                            {uploadingId === invoice.id
                                                ? 'جارٍ الرفع...'
                                                : invoice.receiptUrl
                                                  ? 'استبدال الإيصال'
                                                  : 'إرفاق إيصال'}
                                        </Label>
                                        <input
                                            id={`receipt-${invoice.id}`}
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp,application/pdf"
                                            className="hidden"
                                            disabled={uploadingId === invoice.id}
                                            onChange={(e) => uploadReceipt(invoice.id, e.target.files?.[0])}
                                        />
                                    </div>

                                    <div className="grid grid-cols-2 gap-2 pt-1">
                                        <Button type="button" onClick={() => setPaying(invoice)}>
                                            <CheckCircle2 className="size-4" /> اعتماد الدفع
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="text-destructive hover:text-destructive"
                                            onClick={() => {
                                                setReason('');
                                                setReasonError(null);
                                                setCancelling(invoice);
                                            }}
                                        >
                                            <XCircle className="size-4" /> إلغاء الفاتورة
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}
            </div>

            {/* Mark-paid confirmation */}
            <Dialog open={!!paying} onOpenChange={(open) => !open && setPaying(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>اعتماد دفع الفاتورة</DialogTitle>
                        <DialogDescription>
                            سيتم تحويل الفاتورة {paying?.invoiceNumber} إلى مدفوعة بمبلغ {paying ? formatCurrency(paying.totalAmount) : ''} واحتساب نقاط
                            الولاء إن وُجدت.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setPaying(null)} disabled={submitting}>
                            تراجع
                        </Button>
                        <Button onClick={confirmPay} disabled={submitting}>
                            <CheckCircle2 className="size-4" /> تأكيد الدفع
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Cancel with reason */}
            <Dialog open={!!cancelling} onOpenChange={(open) => !open && setCancelling(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>إلغاء الفاتورة</DialogTitle>
                        <DialogDescription>
                            سيتم إلغاء الفاتورة {cancelling?.invoiceNumber} وعكس عمولة الموظف غير المدفوعة واسترجاع أي نقاط مستبدلة.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-2">
                        <Label htmlFor="cancel-reason">سبب الإلغاء</Label>
                        <textarea
                            id="cancel-reason"
                            value={reason}
                            onChange={(e) => {
                                setReason(e.target.value);
                                setReasonError(null);
                            }}
                            rows={3}
                            className="border-input bg-transparent placeholder:text-muted-foreground focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm shadow-sm focus-visible:ring-1 focus-visible:outline-none"
                            placeholder="اكتب سبب الإلغاء..."
                        />
                        {reasonError && <p className="text-destructive text-xs">{reasonError}</p>}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCancelling(null)} disabled={submitting}>
                            تراجع
                        </Button>
                        <Button variant="destructive" onClick={confirmCancel} disabled={submitting}>
                            <XCircle className="size-4" /> تأكيد الإلغاء
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
