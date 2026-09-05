import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatDateNumeric } from '@/lib/utils';
import attachments from '@/routes/users/attachments';
import { router } from '@inertiajs/react';
import { Download, FileText, Loader2, Paperclip, Trash2 } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

export interface UserAttachment {
    id: number;
    name: string;
    size: number;
    mimeType: string | null;
    uploadedAt: string | null;
    downloadUrl: string;
}

interface Props {
    userId: number;
    canManage: boolean;
}

function formatSize(bytes: number): string {
    if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} م.ب`;

    return `${Math.max(1, Math.round(bytes / 1024))} ك.ب`;
}

/**
 * تاسك 86: مرفقات ملفّ الموظف داخل نافذة التعديل. القائمة تُجلب من مسارها
 * الخاص وتُحدَّث بعد كل رفعٍ أو حذف — لا من حمولة الصفحة: نافذة قائمة
 * المستخدمين تُبنى من نسخةٍ محفوظة في حالة الشاشة، فتبقى قديمةً بعد الرفع.
 *
 * والرفع بمسارٍ مستقلّ لا بنموذج المستخدم: النموذج يرسل PUT وملفٌّ يحتاج
 * multipart، فلا يُقلب النموذج كلّه لأجل قسمٍ واحد.
 */
export default function UserAttachmentsSection({ userId, canManage }: Props) {
    const [items, setItems] = useState<UserAttachment[] | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    const load = useCallback(() => {
        return fetch(attachments.index(userId).url, { headers: { Accept: 'application/json' } })
            .then((res) => res.json())
            .then((data) => setItems(data.attachments ?? []))
            .catch(() => setItems([]));
    }, [userId]);

    useEffect(() => {
        void load();
    }, [load]);

    function handleFiles(files: FileList | null) {
        if (!files || files.length === 0) return;

        setBusy(true);
        setError(null);

        router.post(
            attachments.store(userId).url,
            { files: Array.from(files) },
            {
                forceFormData: true,
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => void load(),
                onError: (errors) => setError(Object.values(errors)[0] ?? 'تعذّر رفع الملف'),
                onFinish: () => {
                    setBusy(false);
                    if (inputRef.current) inputRef.current.value = '';
                },
            },
        );
    }

    function remove(id: number) {
        setBusy(true);

        router.delete(attachments.destroy({ user: userId, media: id }).url, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => void load(),
            onFinish: () => setBusy(false),
        });
    }

    return (
        <div className="space-y-2">
            <Label className="flex items-center gap-1.5">
                <Paperclip className="size-4" />
                المرفقات
            </Label>

            {items === null ? (
                <p className="text-muted-foreground text-xs">جاري التحميل...</p>
            ) : items.length === 0 ? (
                <p className="text-muted-foreground text-xs">لا توجد مرفقات — السيرة الذاتية والعقود وغيرها تُرفع هنا.</p>
            ) : (
                <ul className="space-y-1.5">
                    {items.map((file) => (
                        <li key={file.id} className="bg-muted/40 flex items-center justify-between gap-2 rounded-lg px-3 py-2">
                            <div className="flex min-w-0 items-center gap-2">
                                <FileText className="text-muted-foreground size-4 shrink-0" />
                                <div className="min-w-0">
                                    <p className="truncate text-sm">{file.name}</p>
                                    <p className="text-muted-foreground text-[11px]">
                                        {formatSize(file.size)}
                                        {file.uploadedAt && ` • ${formatDateNumeric(file.uploadedAt)}`}
                                    </p>
                                </div>
                            </div>
                            <div className="flex shrink-0 items-center gap-1">
                                {/* رابطٌ عاديّ لا زرّ Inertia: الملف يُبثّ من الخادم ولا يُعاد رسم صفحة. */}
                                <Button asChild size="icon" variant="ghost" className="size-8" title="تنزيل">
                                    <a href={file.downloadUrl} target="_blank" rel="noopener noreferrer">
                                        <Download className="size-4" />
                                    </a>
                                </Button>
                                {canManage && (
                                    <Button
                                        type="button"
                                        size="icon"
                                        variant="ghost"
                                        className="text-destructive size-8"
                                        title="حذف"
                                        disabled={busy}
                                        onClick={() => remove(file.id)}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {canManage && (
                <div className="space-y-1">
                    <input
                        ref={inputRef}
                        type="file"
                        multiple
                        accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.ppt,.pptx"
                        onChange={(e) => handleFiles(e.target.files)}
                        disabled={busy}
                        className="file:bg-muted file:text-foreground hover:file:bg-muted/80 block w-full cursor-pointer text-xs file:mr-0 file:ml-3 file:cursor-pointer file:rounded-md file:border-0 file:px-3 file:py-1.5 file:text-xs"
                    />
                    <p className="text-muted-foreground text-[11px]">PDF أو صورة أو مستند Office، بحدّ أقصى 10 ميجابايت للملف.</p>
                    {busy && (
                        <p className="text-muted-foreground flex items-center gap-1 text-[11px]">
                            <Loader2 className="size-3 animate-spin" />
                            جاري الرفع...
                        </p>
                    )}
                    {error && <p className="text-destructive text-[11px]">{error}</p>}
                </div>
            )}
        </div>
    );
}
