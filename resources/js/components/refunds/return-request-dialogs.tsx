import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatCurrency } from '@/lib/utils';
import refunds from '@/routes/refunds';
import { type ReturnRequestItem } from '@/types/return-request';
import { router } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

/**
 * نافذتا اعتماد طلب الاسترجاع ورفضه (تاسك 135) — من صفحة الطلبات ومن الفاتورة نفسها (تاسك 185).
 * `approving`/`rejecting` يفتحان النافذة المقابلة؛ `onClose` يُغلقهما.
 */
export default function ReturnRequestDialogs({
    approving,
    rejecting,
    paymentMethods,
    onClose,
}: {
    approving: ReturnRequestItem | null;
    rejecting: ReturnRequestItem | null;
    /** طرق الردّ في فرع الطلب المعتمَد */
    paymentMethods: { id: number; name: string }[];
    onClose: () => void;
}) {
    const [methodId, setMethodId] = useState('');
    // null = لم يُعدَّل بعد ⇒ كامل المبلغ القابل للردّ.
    const [amount, setAmount] = useState<string | null>(null);
    const [rejectionReason, setRejectionReason] = useState('');
    const [processing, setProcessing] = useState(false);

    function close() {
        setMethodId('');
        setAmount(null);
        setRejectionReason('');
        onClose();
    }

    function submit(url: string, data: Record<string, string | null>, success: string) {
        setProcessing(true);
        router.post(url, data, {
            preserveScroll: true,
            onSuccess: () => {
                close();
                toast.success(success);
            },
            onError: (e) => toast.error((Object.values(e)[0] as string) ?? 'تعذّر تنفيذ الطلب.'),
            onFinish: () => setProcessing(false),
        });
    }

    const refundable = approving?.amount ?? 0;
    const needsMethod = refundable > 0;
    const amountText = amount ?? (refundable > 0 ? String(refundable) : '');
    const amountValue = Number(amountText);
    const amountValid = !needsMethod || (amountValue > 0 && amountValue <= refundable);
    const isPartial = needsMethod && amountValid && amountValue < refundable;

    return (
        <>
            <Dialog open={!!approving} onOpenChange={(open) => !open && !processing && close()}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>اعتماد طلب الاسترجاع</DialogTitle>
                        <DialogDescription>
                            {isPartial
                                ? `استرجاع جزئي: يُسجَّل مرتجع بمبلغ ${formatCurrency(amountValue)} وتبقى الفاتورة ${approving?.invoiceNumber} قائمة، وتُعكس العمولة غير المدفوعة ونقاط الولاء بنسبة المبلغ. يُغلق الطلب بعد الاعتماد.`
                                : `تُسترجع الفاتورة ${approving?.invoiceNumber} فوراً: تصير «مرتجع»، ويُسجَّل مرتجع بمبلغ ${formatCurrency(refundable)}، وتُعكس العمولة غير المدفوعة ونقاط الولاء.`}{' '}
                            لا يمكن التراجع عن هذا الإجراء.
                        </DialogDescription>
                    </DialogHeader>
                    {needsMethod && (
                        <div className="space-y-1">
                            <label htmlFor="approve-amount" className="text-sm font-medium">
                                مبلغ الردّ (الحدّ الأقصى {formatCurrency(refundable)})
                            </label>
                            <Input
                                id="approve-amount"
                                type="number"
                                inputMode="decimal"
                                min="0.01"
                                max={refundable}
                                step="0.01"
                                dir="ltr"
                                value={amountText}
                                onChange={(e) => setAmount(e.target.value)}
                                disabled={processing}
                                aria-invalid={!amountValid}
                            />
                            {!amountValid && <p className="text-xs text-destructive">أدخل مبلغاً أكبر من صفر ولا يتجاوز ما حُصِّل.</p>}
                        </div>
                    )}
                    {needsMethod && (
                        <div className="space-y-1">
                            <label htmlFor="approve-method" className="text-sm font-medium">
                                طريقة ردّ المبلغ للعميل
                            </label>
                            <Select value={methodId} onValueChange={setMethodId} disabled={processing}>
                                <SelectTrigger id="approve-method">
                                    <SelectValue placeholder="نقداً أو تحويل بنكي…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {paymentMethods.map((m) => (
                                        <SelectItem key={m.id} value={String(m.id)}>
                                            {m.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                    <DialogFooter>
                        <Button variant="outline" onClick={close} disabled={processing}>
                            تراجع
                        </Button>
                        <Button
                            className="bg-emerald-600 text-white hover:bg-emerald-700"
                            disabled={processing || (needsMethod && !methodId) || !amountValid}
                            onClick={() =>
                                approving &&
                                submit(
                                    refunds.requests.approve(approving.id).url,
                                    { payment_method_id: methodId || null, amount: isPartial ? amountText : null },
                                    isPartial ? 'تم اعتماد الطلب وتسجيل مرتجع جزئي.' : 'تم اعتماد الطلب واسترجاع الفاتورة.',
                                )
                            }
                        >
                            <Check className="size-4" /> {isPartial ? 'اعتماد مرتجع جزئي' : 'اعتماد واسترجاع'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={!!rejecting} onOpenChange={(open) => !open && !processing && close()}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>رفض طلب الاسترجاع</DialogTitle>
                        <DialogDescription>لا تتغيّر الفاتورة {rejecting?.invoiceNumber}، ويصل سبب الرفض للموظف.</DialogDescription>
                    </DialogHeader>
                    <div className="space-y-1">
                        <label htmlFor="rejection-reason" className="text-sm font-medium">
                            سبب الرفض
                        </label>
                        <textarea
                            id="rejection-reason"
                            rows={3}
                            value={rejectionReason}
                            onChange={(e) => setRejectionReason(e.target.value)}
                            disabled={processing}
                            className="border-input bg-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[80px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none disabled:opacity-50"
                        />
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={close} disabled={processing}>
                            تراجع
                        </Button>
                        <Button
                            variant="destructive"
                            disabled={processing || !rejectionReason.trim()}
                            onClick={() =>
                                rejecting &&
                                submit(refunds.requests.reject(rejecting.id).url, { rejection_reason: rejectionReason.trim() }, 'تم رفض طلب الاسترجاع.')
                            }
                        >
                            <X className="size-4" /> رفض الطلب
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
