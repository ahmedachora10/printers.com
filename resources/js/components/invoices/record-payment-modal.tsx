import MaterialsShortageDialog from '@/components/invoices/materials-shortage-dialog';
import { ReceiptField } from '@/components/invoices/receipt-field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatCurrency } from '@/lib/utils';
import payments from '@/routes/invoices/payments';
import { type InvoiceType } from '@/types/invoice';
import { router } from '@inertiajs/react';
import { AlertTriangle, Loader2, Wallet } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

export interface PaymentMethodOption {
    id: number;
    name: string;
    /** طريقة تشترط إثبات تحويل — الإيصال إلزامي معها على كل دفعة. */
    requiresAttachment?: boolean;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    invoiceType: InvoiceType;
    invoiceId: number;
    invoiceNumber: string;
    /** المتبقي على العميل — سقف الدفعة، والقيمة المقترحة لإغلاق الفاتورة. */
    remaining: number;
    paymentMethods: PaymentMethodOption[];
    onRecorded?: () => void;
}

/**
 * تسجيل دفعة على فاتورة: عربوناً كانت أو دفعة لاحقة. السقف هو المتبقي — الخادم
 * يرفض أي مبلغ يتجاوزه، وهذا الحقل يمنع إرساله أصلاً.
 *
 * طريقة الدفع إلزامية ولا تُختار افتراضياً: دفعة بلا طريقة تسقط من تفصيل طرق
 * الدفع في التقارير. والطريقة التي تشترط إيصالاً تفرض رفعه هنا كما تفرضه نقطة
 * البيع على الفاتورة — الشرطان يتكرران على الخادم في StoreInvoicePaymentRequest،
 * وهذه الواجهة راحةٌ لا حارس.
 */
export default function RecordPaymentModal({
    open,
    onOpenChange,
    invoiceType,
    invoiceId,
    invoiceNumber,
    remaining,
    paymentMethods,
    onRecorded,
}: Props) {
    const [amount, setAmount] = useState('');
    const [methodId, setMethodId] = useState<string>('');
    const [receipt, setReceipt] = useState<File | null>(null);
    const [notes, setNotes] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [submitting, setSubmitting] = useState(false);
    // الدفعة التي تُغلق الفاتورة تمرّ بمسار الاعتماد، فقد يردّ الخادم عجز خامات.
    const [materialsShortage, setMaterialsShortage] = useState<string | null>(null);

    // كل فتح جديد يبدأ من حقول نظيفة، فلا تتسرب قيم دفعة سابقة.
    useEffect(() => {
        if (open) {
            setAmount('');
            setMethodId('');
            setReceipt(null);
            setNotes('');
            setErrors({});
            setMaterialsShortage(null);
        }
    }, [open]);

    const hasMethods = paymentMethods.length > 0;
    const selectedMethod = paymentMethods.find((m) => String(m.id) === methodId);
    const requiresReceipt = selectedMethod?.requiresAttachment ?? false;

    const parsed = Number(amount);
    const amountValid = amount.trim() !== '' && Number.isFinite(parsed) && parsed > 0 && parsed <= remaining + 0.001;
    const isValid = amountValid && hasMethods && methodId !== '' && (!requiresReceipt || receipt !== null);
    const settlesInvoice = amountValid && Math.abs(remaining - parsed) < 0.005;

    function submit(confirmedShortage = false) {
        if (!amountValid) {
            setErrors({ amount: `أدخل مبلغاً بين 0.01 و ${remaining.toFixed(2)} ر.س.` });
            return;
        }
        if (methodId === '') {
            setErrors({ payment_method_id: 'طريقة الدفع مطلوبة.' });
            return;
        }
        if (requiresReceipt && receipt === null) {
            setErrors({ receipt: 'يجب إرفاق إيصال التحويل لطريقة الدفع المحددة.' });
            return;
        }

        setSubmitting(true);
        setErrors({});
        // الإيصال يجعل الطلب multipart، فيُجبَر forceFormData حتى حين لا ملف —
        // فتبقى صيغة الإرسال واحدة مهما كانت طريقة الدفع.
        router.post(
            payments.store({ type: invoiceType, id: invoiceId }).url,
            {
                amount: parsed,
                payment_method_id: Number(methodId),
                receipt,
                notes: notes.trim() || null,
                confirm_materials_shortage: confirmedShortage ? 1 : 0,
            },
            {
                forceFormData: true,
                preserveScroll: true,
                onError: (e) => {
                    setErrors(e as Record<string, string>);
                    // عجز الخامات له حواره الخاص، فلا يُصرخ به في toast أيضاً.
                    if (e.materials_shortage) {
                        setMaterialsShortage(e.materials_shortage as string);
                        return;
                    }
                    const first = Object.values(e)[0];
                    if (first) toast.error(first as string);
                },
                onSuccess: () => {
                    onOpenChange(false);
                    onRecorded?.();
                },
                onFinish: () => setSubmitting(false),
            },
        );
    }

    return (
        <>
            <Dialog open={open} onOpenChange={(next) => !submitting && onOpenChange(next)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Wallet className="size-4" /> تسجيل دفعة
                        </DialogTitle>
                        <DialogDescription>
                            الفاتورة {invoiceNumber} — المتبقي {formatCurrency(remaining)}. تُسجَّل الدفعة ولا تُعدَّل بعد حفظها.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4">
                        {/* فرع بلا طرق دفع مفعّلة: يُعطَّل الحفظ ويُشرح السبب بدل إخفاء المنتقي بصمت. */}
                        {!hasMethods && (
                            <div className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300">
                                <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                <span>لا توجد طرق دفع مفعّلة لهذا الفرع — فعّل طريقة دفع من الإعدادات قبل تسجيل الدفعات.</span>
                            </div>
                        )}

                        <div className="space-y-1.5">
                            <Label htmlFor="payment-amount">المبلغ (ر.س)</Label>
                            <div className="flex items-center gap-2">
                                <Input
                                    id="payment-amount"
                                    type="number"
                                    min={0.01}
                                    max={remaining}
                                    step="0.01"
                                    value={amount}
                                    onChange={(e) => setAmount(e.target.value)}
                                    placeholder="0.00"
                                    disabled={submitting}
                                    autoFocus
                                />
                                <Button type="button" variant="outline" onClick={() => setAmount(remaining.toFixed(2))} disabled={submitting}>
                                    المتبقي كاملاً
                                </Button>
                            </div>
                            {errors.amount && <p className="text-destructive text-xs">{errors.amount}</p>}
                            {settlesInvoice && (
                                <p className="text-xs text-green-600 dark:text-green-400">تُغلق هذه الدفعة الفاتورة وتحوّلها إلى مدفوعة.</p>
                            )}
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="payment-method">
                                طريقة الدفع <span className="text-destructive">*</span>
                            </Label>
                            <Select value={methodId} onValueChange={setMethodId} disabled={submitting || !hasMethods}>
                                <SelectTrigger id="payment-method">
                                    <SelectValue placeholder="اختر طريقة الدفع" />
                                </SelectTrigger>
                                <SelectContent>
                                    {paymentMethods.map((m) => (
                                        <SelectItem key={m.id} value={String(m.id)}>
                                            {m.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.payment_method_id && <p className="text-destructive text-xs">{errors.payment_method_id}</p>}
                        </div>

                        {requiresReceipt && (
                            <ReceiptField id="payment-receipt" onChange={setReceipt} error={errors.receipt} disabled={submitting} />
                        )}

                        <div className="space-y-1.5">
                            <Label htmlFor="payment-notes">
                                ملاحظة <span className="text-muted-foreground font-normal">(اختياري)</span>
                            </Label>
                            <textarea
                                id="payment-notes"
                                rows={2}
                                maxLength={500}
                                value={notes}
                                onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setNotes(e.target.value)}
                                placeholder="مثال: عربون نقداً عند الاستلام"
                                disabled={submitting}
                                className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[56px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            {errors.notes && <p className="text-destructive text-xs">{errors.notes}</p>}
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
                            تراجع
                        </Button>
                        <Button onClick={() => submit()} disabled={submitting || !isValid}>
                            {submitting && <Loader2 className="size-4 animate-spin" />} حفظ الدفعة
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <MaterialsShortageDialog
                message={materialsShortage}
                processing={submitting}
                onCancel={() => setMaterialsShortage(null)}
                onConfirm={() => submit(true)}
            />
        </>
    );
}
