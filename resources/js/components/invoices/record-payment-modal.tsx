import MaterialsShortageDialog from '@/components/invoices/materials-shortage-dialog';
import { ReceiptField } from '@/components/invoices/receipt-field';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatCurrency } from '@/lib/utils';
import discount from '@/routes/invoices/discount';
import payments from '@/routes/invoices/payments';
import { type InvoiceType } from '@/types/invoice';
import { router } from '@inertiajs/react';
import { AlertTriangle, BadgePercent, Loader2, Wallet } from 'lucide-react';
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
    /** ما يُحسب عليه الخصم بالنسبة: الإجمالي قبل أي خصم إضافي، بلا التوصيل. */
    discountBase: number;
    /** `discount`: خصمٌ بلا دفعة (زر «إضافة خصم») — بلا طريقة دفع ولا إيصال. */
    mode?: 'payment' | 'discount';
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
    discountBase,
    mode = 'payment',
    paymentMethods,
    onRecorded,
}: Props) {
    const discountOnly = mode === 'discount';
    const [amount, setAmount] = useState('');
    const [discountValue, setDiscountValue] = useState('');
    const [discountIsPct, setDiscountIsPct] = useState(false);
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
            setDiscountValue('');
            setDiscountIsPct(false);
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

    // الخصم بالنسبة يُحوَّل مبلغاً هنا؛ الخادم يستقبل المبلغ ويتحقق من حدوده.
    const discountInput = Number(discountValue) || 0;
    const discountAmount = Math.round((discountIsPct ? (discountBase * discountInput) / 100 : discountInput) * 100) / 100;
    const remainingAfterDiscount = Math.round((remaining - discountAmount) * 100) / 100;
    // في وضع الخصم وحده يُقبل السالب تصحيحاً لخصمٍ سابق؛ مع الدفعة لا.
    const discountValid = remainingAfterDiscount >= 0 && (discountOnly ? discountAmount !== 0 : discountAmount >= 0);

    const parsed = Number(amount);
    const amountValid = amount.trim() !== '' && Number.isFinite(parsed) && parsed > 0 && parsed <= remainingAfterDiscount + 0.001;
    const isValid = discountOnly
        ? discountValid
        : amountValid && discountValid && hasMethods && methodId !== '' && (!requiresReceipt || receipt !== null);
    const settlesInvoice = discountOnly
        ? discountValid && remainingAfterDiscount < 0.005
        : amountValid && Math.abs(remainingAfterDiscount - parsed) < 0.005;

    function submit(confirmedShortage = false) {
        if (!discountOnly && !amountValid) {
            setErrors({ amount: `أدخل مبلغاً بين 0.01 و ${remainingAfterDiscount.toFixed(2)} ر.س.` });
            return;
        }
        if (!discountOnly && methodId === '') {
            setErrors({ payment_method_id: 'طريقة الدفع مطلوبة.' });
            return;
        }
        if (!discountOnly && requiresReceipt && receipt === null) {
            setErrors({ receipt: 'يجب إرفاق إيصال التحويل لطريقة الدفع المحددة.' });
            return;
        }

        setSubmitting(true);
        setErrors({});
        // الإيصال يجعل الطلب multipart، فيُجبَر forceFormData حتى حين لا ملف —
        // فتبقى صيغة الإرسال واحدة مهما كانت طريقة الدفع.
        router.post(
            discountOnly ? discount.store({ type: invoiceType, id: invoiceId }).url : payments.store({ type: invoiceType, id: invoiceId }).url,
            discountOnly
                ? { amount: discountAmount, confirm_materials_shortage: confirmedShortage ? 1 : 0 }
                : {
                      amount: parsed,
                      discount: discountAmount > 0 ? discountAmount : null,
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
                            {discountOnly ? <BadgePercent className="size-4" /> : <Wallet className="size-4" />}
                            {discountOnly ? 'إضافة خصم' : 'تسجيل دفعة'}
                        </DialogTitle>
                        <DialogDescription>
                            الفاتورة {invoiceNumber} — المتبقي {formatCurrency(remaining)}.{' '}
                            {discountOnly
                                ? 'يُنزل الخصم الإجمالي والضريبة وعمولة الموظف. لتصحيح خصمٍ سابق أدخل قيمة سالبة.'
                                : 'تُسجَّل الدفعة ولا تُعدَّل بعد حفظها.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4">
                        {/* الخصم بمبلغ أو بنسبة من الإجمالي قبل الخصم الإضافي. مع الدفعة
                            يُطبَّق أولاً، فتُقاس الدفعة على المتبقي بعده. */}
                        <div className="space-y-1.5">
                            <Label htmlFor="payment-discount">
                                الخصم {!discountOnly && <span className="text-muted-foreground font-normal">(اختياري)</span>}
                            </Label>
                            <div className="flex items-center gap-2">
                                <Input
                                    id="payment-discount"
                                    type="number"
                                    step="0.01"
                                    min={discountOnly ? undefined : 0}
                                    value={discountValue}
                                    onChange={(e) => setDiscountValue(e.target.value)}
                                    placeholder="0.00"
                                    disabled={submitting}
                                    autoFocus={discountOnly}
                                />
                                <div className="flex shrink-0 overflow-hidden rounded-md border">
                                    {[false, true].map((pct) => (
                                        <button
                                            key={String(pct)}
                                            type="button"
                                            onClick={() => setDiscountIsPct(pct)}
                                            disabled={submitting}
                                            className={`px-3 py-1.5 text-sm ${discountIsPct === pct ? 'bg-primary text-primary-foreground' : 'bg-background'}`}
                                        >
                                            {pct ? '%' : 'ر.س'}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            {discountAmount !== 0 &&
                                (discountValid ? (
                                    <p className="text-muted-foreground text-xs">
                                        {discountIsPct && `الخصم ${formatCurrency(discountAmount)} — `}المتبقي بعد الخصم {formatCurrency(remainingAfterDiscount)}
                                    </p>
                                ) : (
                                    <p className="text-destructive text-xs">الخصم يتجاوز المتبقي على الفاتورة.</p>
                                ))}
                            {errors.discount && <p className="text-destructive text-xs">{errors.discount}</p>}
                            {discountOnly && settlesInvoice && (
                                <p className="text-xs text-green-600 dark:text-green-400">المحصَّل يغطي الإجمالي بعد الخصم — تُغلق الفاتورة وتصير مدفوعة.</p>
                            )}
                        </div>

                        {!discountOnly && (
                            <>
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
                                    max={remainingAfterDiscount}
                                    step="0.01"
                                    value={amount}
                                    onChange={(e) => setAmount(e.target.value)}
                                    placeholder="0.00"
                                    disabled={submitting}
                                    autoFocus
                                />
                                <Button type="button" variant="outline" onClick={() => setAmount(remainingAfterDiscount.toFixed(2))} disabled={submitting}>
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
                            </>
                        )}
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => onOpenChange(false)} disabled={submitting}>
                            تراجع
                        </Button>
                        <Button onClick={() => submit()} disabled={submitting || !isValid}>
                            {submitting && <Loader2 className="size-4 animate-spin" />} {discountOnly ? 'حفظ الخصم' : 'حفظ الدفعة'}
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
