import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type DeliveryLogRow } from '@/types/delivery-log';
import { useForm } from '@inertiajs/react';

/** تاسك 111 — تسوية أجر السائق لطلب توصيل وإلغاؤها؛ من كشف التوصيل ومن الفاتورة (تاسك 190). */
export type SettleTarget = Pick<DeliveryLogRow, 'id' | 'invoiceNumber' | 'shippingFee' | 'providerName' | 'settlement'>;

const SOURCES = [
    { value: 'cash_drawer', label: 'نقداً لدى المحاسب' },
    { value: 'company_transfer', label: 'تحويل من حساب الشركة' },
];

export function SettleDeliveryDialog({ row, categories, onClose }: { row: SettleTarget; categories: { id: number; name: string }[]; onClose: () => void }) {
    const { data, setData, post, processing, errors } = useForm({
        amount: row.shippingFee > 0 ? String(row.shippingFee) : '',
        paid_from: '',
        expense_category_id: '',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post(`/shipping/deliveries/${row.id}/settle`, { preserveScroll: true, onSuccess: onClose });
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        تسليم مبلغ التوصيل — <span dir="ltr">{row.invoiceNumber}</span>
                    </DialogTitle>
                </DialogHeader>
                <form id="settle-form" onSubmit={submit} className="space-y-4">
                    <p className="text-muted-foreground text-sm">السائق: {row.providerName ?? '—'}</p>
                    <div className="space-y-1">
                        <Label htmlFor="settle-amount">المبلغ (ر.س)</Label>
                        <Input id="settle-amount" type="number" min="0.01" step="0.01" dir="ltr" value={data.amount} onChange={(e) => setData('amount', e.target.value)} />
                        <InputError message={errors.amount} />
                    </div>
                    <div className="space-y-1">
                        <Label>طريقة التسوية</Label>
                        <div className="grid grid-cols-2 gap-2">
                            {SOURCES.map((s) => (
                                <Button
                                    key={s.value}
                                    type="button"
                                    variant={data.paid_from === s.value ? 'default' : 'outline'}
                                    aria-pressed={data.paid_from === s.value}
                                    onClick={() => setData('paid_from', s.value)}
                                >
                                    {s.label}
                                </Button>
                            ))}
                        </div>
                        <InputError message={errors.paid_from} />
                    </div>
                    <div className="space-y-1">
                        <Label>فئة المصروف</Label>
                        <Select value={data.expense_category_id} onValueChange={(v) => setData('expense_category_id', v)}>
                            <SelectTrigger>
                                <SelectValue placeholder="اختر الفئة" />
                            </SelectTrigger>
                            <SelectContent>
                                {categories.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>
                                        {c.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.expense_category_id} />
                    </div>
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="settle-form" disabled={processing}>
                        تسجيل التسليم
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export function CancelSettlementDialog({ row, onClose }: { row: SettleTarget; onClose: () => void }) {
    const form = useForm({ reason: '' });

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        إلغاء تسوية <span dir="ltr">{row.invoiceNumber}</span>
                    </DialogTitle>
                </DialogHeader>
                <p className="text-muted-foreground text-sm">يُحذف مصروف التسوية ويُتاح تسجيل التسليم من جديد. السبب يُحفظ في السجلّ.</p>
                <div className="space-y-1">
                    <Label htmlFor="cancel-reason">السبب</Label>
                    <Input id="cancel-reason" value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
                    <InputError message={form.errors.reason} />
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={onClose} disabled={form.processing}>
                        تراجع
                    </Button>
                    <Button
                        variant="destructive"
                        disabled={form.processing}
                        onClick={() => form.delete(`/shipping/settlements/${row.settlement!.expenseId}`, { preserveScroll: true, onSuccess: onClose })}
                    >
                        إلغاء التسوية
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
