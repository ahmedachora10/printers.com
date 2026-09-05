import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Paperclip } from 'lucide-react';

/**
 * حقل إرفاق إيصال التحويل — الشكل الواحد الذي تعرضه كلُّ شاشة تختار فيها
 * طريقةَ دفعٍ تشترط مرفقاً: نقطة بيع الخدمات، نقطة بيع المنتجات، نافذة تسجيل
 * الدفعة، ونافذة تعديل طريقة دفع الفاتورة. كان مكرَّراً في كل واحدة منها،
 * فاختلفت رسائلها وحدود ملفاتها بلا سبب.
 *
 * الأنواع والحد الأقصى هنا مرآةٌ لقواعد الخادم (`mimes:jpg,jpeg,png,webp,pdf`
 * و`max:5120`) — تغييرُ أحدهما يستلزم الآخر.
 */

export const RECEIPT_ACCEPT = 'image/jpeg,image/png,image/webp,application/pdf';

interface ReceiptFieldProps {
    /** معرّف الحقل — يلزم أن يكون فريداً في الصفحة الواحدة. */
    id: string;
    onChange: (file: File | null) => void;
    error?: string;
    disabled?: boolean;
    /** رابط الإيصال المحفوظ سابقاً، إن وُجد: يصير الرفعُ استبدالاً اختيارياً. */
    existingUrl?: string | null;
}

export function ReceiptField({ id, onChange, error, disabled, existingUrl }: ReceiptFieldProps) {
    return (
        <div className="space-y-1.5 rounded-md border border-amber-500/40 bg-amber-500/10 p-3">
            <Label htmlFor={id} className="flex items-center gap-1.5 text-sm text-amber-700 dark:text-amber-400">
                <Paperclip className="size-4" /> إيصال التحويل{' '}
                {existingUrl ? <span className="text-muted-foreground font-normal">(اختياري — يستبدل المرفق الحالي)</span> : '(مطلوب)'}
            </Label>
            {existingUrl && (
                <a href={existingUrl} target="_blank" rel="noopener noreferrer" className="text-primary block text-xs hover:underline">
                    عرض الإيصال المرفق حالياً
                </a>
            )}
            <Input
                id={id}
                type="file"
                accept={RECEIPT_ACCEPT}
                disabled={disabled}
                onChange={(e) => onChange(e.target.files?.[0] ?? null)}
                className="cursor-pointer"
            />
            <p className="text-muted-foreground text-xs">صورة (jpg, png, webp) أو ملف PDF — بحد أقصى 5 ميجابايت.</p>
            {error && <p className="text-destructive text-xs">{error}</p>}
        </div>
    );
}
