import ReceivePoModal from '@/components/purchase-orders/receive-po-modal';
import { DataTable, type ColumnDef } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { PO_STATUS_BADGE, type PurchaseOrder, type PurchaseOrderLine } from '@/types/purchase-order';
import { router } from '@inertiajs/react';
import { PackageCheck, Send, Trash2, XCircle } from 'lucide-react';
import { useMemo, useState } from 'react';
import inventory from '@/routes/inventory';

interface Props {
    purchaseOrder: PurchaseOrder;
}

export default function PurchaseOrderShow({ purchaseOrder: po }: Props) {
    const [receiveOpen, setReceiveOpen] = useState(false);
    const [confirm, setConfirm] = useState<null | 'cancel' | 'delete'>(null);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'المستودع', href: inventory.products.index().url },
        { title: 'أوامر الشراء', href: inventory.purchaseOrders.index().url },
        { title: po.poNumber, href: inventory.purchaseOrders.show(po.id).url },
    ];

    const columns = useMemo<ColumnDef<PurchaseOrderLine>[]>(
        () => [
            {
                key: 'productName',
                header: 'المنتج',
                cell: (line) => <span className="font-medium">{line.productName}</span>,
            },
            {
                key: 'sku',
                header: 'SKU',
                cell: (line) => (
                    <span className="font-mono text-xs text-muted-foreground">{line.sku ?? '—'}</span>
                ),
            },
            { key: 'orderedQty', header: 'المطلوب', cell: (line) => line.orderedQty },
            {
                key: 'receivedQty',
                header: 'المستلم',
                cell: (line) => <span className="tabular-nums">{line.receivedQty}</span>,
            },
            {
                key: 'remainingQty',
                header: 'المتبقي',
                cell: (line) => (
                    <span className={line.remainingQty > 0 ? 'font-semibold text-amber-700' : 'text-muted-foreground'}>
                        {line.remainingQty}
                    </span>
                ),
            },
            {
                key: 'unitCost',
                header: 'سعر الوحدة',
                cell: (line) => (
                    <span dir="ltr" className="tabular-nums">
                        {formatCurrency(line.unitCost)}
                    </span>
                ),
            },
            {
                key: 'subtotal',
                header: 'الإجمالي',
                cell: (line) => (
                    <span dir="ltr" className="tabular-nums">
                        {formatCurrency(line.subtotal)}
                    </span>
                ),
            },
        ],
        [],
    );

    const handleMarkSent = () => router.patch(inventory.purchaseOrders.sent(po.id).url, {}, { preserveScroll: true });

    const handleCancel = () =>
        router.patch(inventory.purchaseOrders.cancel(po.id).url, {}, {
            preserveScroll: true,
            onFinish: () => setConfirm(null),
        });

    const handleDelete = () =>
        router.delete(inventory.purchaseOrders.destroy(po.id).url, {
            onSuccess: () => router.visit(inventory.purchaseOrders.index().url),
            onFinish: () => setConfirm(null),
        });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <h1 className="font-mono text-2xl font-bold tracking-wide">{po.poNumber}</h1>
                        <Badge variant="outline" className={PO_STATUS_BADGE[po.status]}>
                            {po.statusLabel}
                        </Badge>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {po.status === 'draft' && (
                            <Button size="sm" onClick={handleMarkSent}>
                                <Send className="size-4" /> إرسال الأمر
                            </Button>
                        )}
                        {po.canReceive && (
                            <Button size="sm" onClick={() => setReceiveOpen(true)}>
                                <PackageCheck className="size-4" /> تسجيل استلام
                            </Button>
                        )}
                        {po.canCancel && (
                            <Button size="sm" variant="outline" className="text-destructive hover:text-destructive" onClick={() => setConfirm('cancel')}>
                                <XCircle className="size-4" /> إلغاء
                            </Button>
                        )}
                        {po.canEdit && (
                            <Button size="sm" variant="outline" className="text-destructive hover:text-destructive" onClick={() => setConfirm('delete')}>
                                <Trash2 className="size-4" /> حذف
                            </Button>
                        )}
                    </div>
                </div>

                <div className="mb-6 grid grid-cols-1 gap-4 rounded-lg border p-4 text-sm sm:grid-cols-4">
                    <div>
                        <p className="text-muted-foreground">المورد</p>
                        <p className="font-medium">{po.supplierName ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">تاريخ الأمر</p>
                        <p className="font-medium" dir="ltr">{po.orderDate ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">التسليم المتوقع</p>
                        <p className="font-medium" dir="ltr">{po.expectedDelivery ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">أنشأه</p>
                        <p className="font-medium">{po.orderedByName ?? '—'}</p>
                    </div>
                </div>

                <DataTable columns={columns} data={po.lines ?? []} keyExtractor={(line) => line.id} />

                <div className="mt-4 flex items-center justify-end gap-2">
                    <span className="text-sm text-muted-foreground">الإجمالي:</span>
                    <span dir="ltr" className="text-lg font-bold tabular-nums">
                        {formatCurrency(po.total ?? 0)}
                    </span>
                </div>

                {po.notes && (
                    <div className="mt-6 rounded-lg border bg-muted/30 p-4 text-sm">
                        <p className="mb-1 font-medium">ملاحظات</p>
                        <p className="text-muted-foreground">{po.notes}</p>
                    </div>
                )}
            </div>

            <ReceivePoModal key={po.lines?.map((l) => l.receivedQty).join('-')} open={receiveOpen} onOpenChange={setReceiveOpen} purchaseOrder={po} />

            <Dialog open={!!confirm} onOpenChange={(open) => !open && setConfirm(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{confirm === 'delete' ? 'تأكيد الحذف' : 'تأكيد الإلغاء'}</DialogTitle>
                        <DialogDescription>
                            {confirm === 'delete'
                                ? `هل أنت متأكد من حذف أمر الشراء "${po.poNumber}"؟`
                                : `هل أنت متأكد من إلغاء أمر الشراء "${po.poNumber}"؟`}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirm(null)}>
                            تراجع
                        </Button>
                        <Button variant="destructive" onClick={confirm === 'delete' ? handleDelete : handleCancel}>
                            {confirm === 'delete' ? 'حذف' : 'إلغاء الأمر'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
