import { receive } from '@/actions/App/Http/Controllers/PurchaseOrderController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { type PurchaseOrder } from '@/types/purchase-order';
import { useForm } from '@inertiajs/react';
import { useEffect, useMemo } from 'react';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    purchaseOrder: PurchaseOrder;
}

export default function ReceivePoModal({ open, onOpenChange, purchaseOrder }: Props) {
    const openLines = useMemo(
        () => (purchaseOrder.lines ?? []).filter((line) => line.remainingQty > 0),
        [purchaseOrder.lines],
    );

    const { data, setData, post, processing, errors, reset } = useForm<{
        receipts: { line_id: number; qty: string }[];
    }>({
        receipts: openLines.map((line) => ({ line_id: line.id, qty: '' })),
    });

    useEffect(() => {
        setData('receipts', openLines.map((line) => ({ line_id: line.id, qty: '' })));
    }, [openLines, open]);

    const setQty = (lineId: number, qty: string) => {
        setData(
            'receipts',
            data.receipts.map((r) => (r.line_id === lineId ? { ...r, qty } : r)),
        );
    };

    const qtyFor = (lineId: number) => data.receipts.find((r) => r.line_id === lineId)?.qty ?? '';

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post(receive.url(purchaseOrder.id), {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                reset();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>تسجيل استلام — {purchaseOrder.poNumber}</DialogTitle>
                </DialogHeader>

                <form id="receive-form" onSubmit={handleSubmit} className="space-y-3 py-2">
                    <div className="grid grid-cols-12 gap-2 px-1 text-xs font-medium text-muted-foreground">
                        <span className="col-span-6">المنتج</span>
                        <span className="col-span-3 text-center">المتبقي</span>
                        <span className="col-span-3 text-center">الكمية المستلمة</span>
                    </div>

                    {openLines.map((line) => (
                        <div key={line.id} className="grid grid-cols-12 items-center gap-2">
                            <span className="col-span-6 text-sm font-medium">{line.productName}</span>
                            <span className="col-span-3 text-center tabular-nums text-muted-foreground">
                                {line.remainingQty}
                            </span>
                            <div className="col-span-3">
                                <Input
                                    type="number"
                                    min="0"
                                    max={line.remainingQty}
                                    dir="ltr"
                                    placeholder="0"
                                    value={qtyFor(line.id)}
                                    onChange={(e) => setQty(line.id, e.target.value)}
                                />
                            </div>
                        </div>
                    ))}

                    {typeof errors.receipts === 'string' && <InputError message={errors.receipts} />}

                    {openLines.length === 0 && (
                        <p className="py-4 text-center text-sm text-muted-foreground">
                            تم استلام جميع البنود.
                        </p>
                    )}
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="receive-form" disabled={processing || openLines.length === 0}>
                        {processing ? 'جاري الحفظ...' : 'تسجيل الاستلام'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
