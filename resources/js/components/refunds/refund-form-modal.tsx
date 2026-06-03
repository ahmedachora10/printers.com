import { store } from '@/routes/refunds';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCurrency } from '@/lib/utils';
import { type InvoiceLookupResult } from '@/types/refund';
import { useForm } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import InputError from '../input-error';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Pre-fill and auto-look-up a specific invoice (e.g. from the invoice details page). */
    presetNumber?: string;
}

export default function RefundFormModal({ open, onOpenChange, presetNumber }: Props) {
    const [number, setNumber] = useState(presetNumber ?? '');
    const [looking, setLooking] = useState(false);
    const [invoice, setInvoice] = useState<InvoiceLookupResult | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm({
        source_type: '' as string,
        invoice_id: 0,
        amount: '' as number | string,
        reason: '',
        reverse_stock: false as boolean,
    });

    // When opened for a specific invoice, resolve it immediately.
    useEffect(() => {
        if (open && presetNumber && !invoice) {
            handleLookup(presetNumber);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, presetNumber]);

    async function handleLookup(value?: string) {
        const q = (value ?? number).trim();
        if (!q) return;
        setLooking(true);
        try {
            const res = await fetch(`/refunds/lookup?number=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const result = await res.json();
            if (result.found) {
                const inv: InvoiceLookupResult = result.invoice;
                if (inv.refundable <= 0) {
                    toast.error('تم إرجاع كامل مبلغ هذه الفاتورة.');
                    setInvoice(null);
                    return;
                }
                setInvoice(inv);
                setData('source_type', inv.type);
                setData('invoice_id', inv.id);
                setData('amount', inv.refundable);
                setData('reverse_stock', inv.hasProducts && !inv.stockReversed);
            } else {
                setInvoice(null);
                toast.error(result.message ?? 'لم يتم العثور على الفاتورة.');
            }
        } catch {
            toast.error('تعذر البحث عن الفاتورة.');
        } finally {
            setLooking(false);
        }
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (!invoice) return;

        post(store.url(), {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                reset();
                setInvoice(null);
                setNumber('');
            },
        });
    }

    const canReverseStock = invoice?.type === 'product' && invoice.hasProducts && !invoice.stockReversed;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>إنشاء مرتجع</DialogTitle>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    <div className="space-y-1">
                        <Label htmlFor="invoice-number">رقم الفاتورة</Label>
                        <div className="flex gap-2">
                            <Input
                                id="invoice-number"
                                value={number}
                                onChange={(e) => setNumber(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        handleLookup();
                                    }
                                }}
                                placeholder="INV-001-00001"
                                dir="ltr"
                                readOnly={!!presetNumber}
                            />
                            {!presetNumber && (
                                <Button type="button" variant="outline" onClick={() => handleLookup()} disabled={looking}>
                                    <Search className="size-4" />
                                </Button>
                            )}
                        </div>
                        <InputError message={errors.invoice_id} />
                    </div>

                    {invoice && (
                        <form id="refund-form" onSubmit={handleSubmit} className="space-y-4">
                            <div className="space-y-1.5 rounded-lg border bg-muted/40 p-3 text-sm">
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">النوع</span>
                                    <span className="font-medium">{invoice.typeLabel}</span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">العميل</span>
                                    <span>{invoice.customerName ?? 'عميل نقدي'}</span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">إجمالي الفاتورة</span>
                                    <span className="font-medium" dir="ltr">{formatCurrency(invoice.totalAmount)}</span>
                                </div>
                                <div className="flex items-center justify-between">
                                    <span className="text-muted-foreground">المبلغ القابل للإرجاع</span>
                                    <span className="font-semibold text-amber-600" dir="ltr">
                                        {formatCurrency(invoice.refundable)}
                                    </span>
                                </div>
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="amount">مبلغ المرتجع</Label>
                                <Input
                                    id="amount"
                                    type="number"
                                    step="0.01"
                                    min="0.01"
                                    max={invoice.refundable}
                                    value={data.amount}
                                    onChange={(e) => setData('amount', e.target.value)}
                                    dir="ltr"
                                />
                                <InputError message={errors.amount} />
                            </div>

                            <div className="space-y-1">
                                <Label htmlFor="reason">سبب الإرجاع</Label>
                                <textarea
                                    id="reason"
                                    rows={3}
                                    value={data.reason}
                                    onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setData('reason', e.target.value)}
                                    placeholder="سبب إرجاع الفاتورة..."
                                    className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                />
                                <InputError message={errors.reason} />
                            </div>

                            {canReverseStock && (
                                <label className="flex items-center gap-2 rounded-md border p-3 text-sm">
                                    <Checkbox
                                        checked={data.reverse_stock}
                                        onCheckedChange={(checked) => setData('reverse_stock', checked === true)}
                                    />
                                    <span>إرجاع المنتجات إلى المخزون</span>
                                </label>
                            )}

                            {invoice.type === 'product' && invoice.stockReversed && (
                                <p className="text-xs text-muted-foreground">تم إرجاع مخزون هذه الفاتورة مسبقاً.</p>
                            )}
                        </form>
                    )}
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="refund-form" disabled={processing || !invoice}>
                        {processing ? 'جاري الحفظ...' : 'تأكيد المرتجع'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
