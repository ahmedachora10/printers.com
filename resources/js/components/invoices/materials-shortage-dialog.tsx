import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';

interface Props {
    /** نص العجز الآتي من الخادم — null يعني لا عجز، فلا حوار */
    message: string | null;
    processing?: boolean;
    onCancel: () => void;
    onConfirm: () => void;
}

/**
 * وقفةُ عجز الخامات قبل اعتماد فاتورة خدمة: الخادم يرفض الاعتماد الأول ويردّ
 * الناقص، فيُعرض هنا ويُعاد الإرسال بإقرارٍ صريح.
 *
 * ليست منعاً: الشغل قد سُلِّم للعميل فعلاً، ورفضُ اعتماده يعلّق الفاتورة في قائمة
 * المراجعة بلا مخرج — لكن المرور صامتاً يُخفي أن رصيد الخامة صار سالباً.
 */
export default function MaterialsShortageDialog({ message, processing = false, onCancel, onConfirm }: Props) {
    return (
        <Dialog open={message !== null} onOpenChange={(open) => !open && !processing && onCancel()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>المخزون لا يكفي خامات الفاتورة</DialogTitle>
                    <DialogDescription>
                        يمكنك الاعتماد على أي حال — سيصبح رصيد الخامة سالباً، وهو ما يقول إن الجرد لازم — أو تراجع وأدخل المشتريات أولاً.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                    <p className="leading-relaxed">{message}</p>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onCancel} disabled={processing}>
                        تراجع
                    </Button>
                    <Button onClick={onConfirm} disabled={processing}>
                        <CheckCircle2 className="size-4" /> اعتماد على أي حال
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
