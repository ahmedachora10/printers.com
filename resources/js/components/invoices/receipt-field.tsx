import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Paperclip, X } from 'lucide-react';

/**
 * حقل إرفاق إيصالات التحويل — الشكل الواحد الذي تعرضه كلُّ شاشة تختار فيها
 * طريقةَ دفعٍ تشترط مرفقاً: نقطة بيع الخدمات، نقطة بيع المنتجات، نافذة تسجيل
 * الدفعة، نافذة تعديل طريقة دفع الفاتورة، والمرتجعات.
 *
 * الأنواع والحدود هنا مرآةٌ لقواعد الخادم (HasReceiptMedia::receiptRules:
 * حتى 5 ملفات، `mimes:jpg,jpeg,png,webp,pdf` و`max:5120`) — تغييرُ أحدهما يستلزم الآخر.
 */

export const RECEIPT_ACCEPT = 'image/jpeg,image/png,image/webp,application/pdf';

/** إيصالٌ محفوظ برابطه المحمي. */
export interface ReceiptFile {
    id: number;
    name: string;
    url: string;
}

/** خطأ الحقل أياً كان مفتاحه: `receipts` للقائمة أو `receipts.N` لملفٍ بعينه. */
export function receiptError(errors: Record<string, string | undefined>): string | undefined {
    return errors.receipts ?? Object.entries(errors).find(([key]) => key.startsWith('receipts.'))?.[1];
}

interface ReceiptFieldProps {
    /** معرّف الحقل — يلزم أن يكون فريداً في الصفحة الواحدة. */
    id: string;
    onChange: (files: File[]) => void;
    error?: string;
    disabled?: boolean;
    /** الإيصالات المحفوظة سابقاً، إن وُجدت: الرفع يُضيف إليها ولا يستبدلها. */
    existing?: ReceiptFile[];
}

export function ReceiptField({ id, onChange, error, disabled, existing = [] }: ReceiptFieldProps) {
    return (
        <div className="space-y-1.5 rounded-md border border-amber-500/40 bg-amber-500/10 p-3">
            <Label htmlFor={id} className="flex items-center gap-1.5 text-sm text-amber-700 dark:text-amber-400">
                <Paperclip className="size-4" /> إيصال التحويل{' '}
                {existing.length > 0 ? <span className="text-muted-foreground font-normal">(اختياري — يُضاف إلى المرفق الحالي)</span> : '(مطلوب)'}
            </Label>
            <ReceiptLinks receipts={existing} />
            <Input
                id={id}
                type="file"
                multiple
                accept={RECEIPT_ACCEPT}
                disabled={disabled}
                onChange={(e) => onChange(Array.from(e.target.files ?? []))}
                className="cursor-pointer"
            />
            <p className="text-muted-foreground text-xs">حتى 5 ملفات — صورة (jpg, png, webp) أو PDF، كلٌّ بحد أقصى 5 ميجابايت.</p>
            {error && <p className="text-destructive text-xs">{error}</p>}
        </div>
    );
}

/**
 * روابط الإيصالات المحفوظة. وسمٌ عارٍ لا Link: الوجهة ملفٌ يُبَثّ من القرص الخاص
 * لا صفحة Inertia. الحذف لمدير الفرع والسوبر أدمن وحدهما (والخادم يتحقّق).
 */
export function ReceiptLinks({ receipts }: { receipts: ReceiptFile[] }) {
    const role = usePage<SharedData>().props.auth.role;
    const canDelete = role === 'super-admin' || role === 'branch-admin';

    if (receipts.length === 0) return null;

    return (
        <span className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
            {receipts.map((receipt, i) => (
                <span key={receipt.id} className="inline-flex items-center gap-1">
                    <a
                        href={receipt.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        title={receipt.name}
                        className="text-primary inline-flex items-center gap-1 hover:underline"
                    >
                        <Paperclip className="size-3" /> {receipts.length > 1 ? `الإيصال ${i + 1}` : 'الإيصال'}
                    </a>
                    {canDelete && (
                        <button
                            type="button"
                            title="حذف الإيصال"
                            className="text-muted-foreground hover:text-destructive"
                            onClick={() =>
                                window.confirm('حذف هذا الإيصال نهائياً؟') &&
                                router.delete(`/invoices/receipts/${receipt.id}`, { preserveScroll: true })
                            }
                        >
                            <X className="size-3" />
                            <span className="sr-only">حذف الإيصال</span>
                        </button>
                    )}
                </span>
            ))}
        </span>
    );
}
