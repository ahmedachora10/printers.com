import { Button } from '@/components/ui/button';
import { internalNotes as internalNotesRoute } from '@/routes/invoices';
import { router } from '@inertiajs/react';
import { Loader2, Lock, Pencil } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

/**
 * تاسك 95 — الملاحظة الداخلية على شاشة الفاتورة.
 *
 * تُميَّز بصرياً عن كتلة «ملاحظات الفاتورة» (InvoiceNotes) عمداً: قفلٌ ووسمٌ
 * صريح «لا تظهر للعميل»، فالخلط بين الاثنتين يعني كتابة تعليمةٍ داخلية في
 * ورقةٍ تصل العميل. والخادم يحذفها من حمولة الطباعة، فلا شيء هنا يحرسها.
 *
 * وتبقى قابلة للتعديل بعد الاعتماد — تعليمات تنفيذٍ لا رقمٌ مالي — ويُسجَّل
 * كل تغيير في سجلّ النشاط.
 */
export default function InternalNotesCard({
    type,
    id,
    notes,
    canEdit,
}: {
    type: 'product' | 'service';
    id: number;
    notes: string | null | undefined;
    canEdit: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(notes ?? '');
    const [saving, setSaving] = useState(false);

    // لا شيء يُكتب ولا شيء يُعرض: الكتلة تختفي كلياً.
    if (!canEdit && (!notes || notes.trim() === '')) return null;

    function save() {
        setSaving(true);
        router.patch(
            internalNotesRoute({ type, id }).url,
            { internal_notes: draft.trim() },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setEditing(false);
                    toast.success('تم حفظ الملاحظات الداخلية.');
                },
                onError: (e) => toast.error((Object.values(e)[0] as string) ?? 'تعذّر حفظ الملاحظات الداخلية.'),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <div className="mt-4 rounded-md border border-amber-300 bg-amber-50/60 p-3 dark:border-amber-900 dark:bg-amber-950/30">
            <div className="mb-1 flex items-center justify-between gap-2">
                <p className="flex items-center gap-1.5 text-sm font-semibold text-amber-900 dark:text-amber-200">
                    <Lock className="size-3.5" aria-hidden />
                    ملاحظات داخلية — لا تظهر للعميل
                </p>
                {canEdit && !editing && (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 text-amber-900 hover:text-amber-950 dark:text-amber-200"
                        onClick={() => {
                            setDraft(notes ?? '');
                            setEditing(true);
                        }}
                    >
                        <Pencil className="size-3.5" /> {notes ? 'تعديل' : 'إضافة'}
                    </Button>
                )}
            </div>

            {editing ? (
                <div className="space-y-2">
                    <textarea
                        rows={3}
                        maxLength={1000}
                        value={draft}
                        onChange={(e) => setDraft(e.target.value)}
                        disabled={saving}
                        placeholder="تعليمات التنفيذ أو تنبيه للمحاسب — لا تُطبع في الفاتورة"
                        className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[72px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                    />
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" size="sm" onClick={() => setEditing(false)} disabled={saving}>
                            إلغاء
                        </Button>
                        <Button size="sm" onClick={save} disabled={saving}>
                            {saving && <Loader2 className="size-4 animate-spin" />} حفظ
                        </Button>
                    </div>
                </div>
            ) : notes && notes.trim() !== '' ? (
                <p className="text-sm whitespace-pre-line text-amber-900 dark:text-amber-100">{notes}</p>
            ) : (
                <p className="text-xs text-amber-800/70 dark:text-amber-200/70">لا توجد ملاحظات داخلية على هذه الفاتورة.</p>
            )}
        </div>
    );
}
