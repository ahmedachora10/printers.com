import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import { cn, formatDateTimeNumeric } from '@/lib/utils';
import messagesRoute from '@/routes/invoices/messages';
import { type InvoiceMessage, type InvoiceThread as Thread } from '@/types/invoice';
import { router, usePoll } from '@inertiajs/react';
import { AtSign, CheckCheck, Loader2, Lock, LockOpen, Paperclip, Pencil, Send, Trash2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

type Mentionable = Thread['mentionables'][number];

const textareaClass =
    'border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[72px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50';

// كل كتابة تعيد الخيط وحده — لا تُعاد الفاتورة ولا يقفز التمرير.
const reload = { preserveScroll: true, only: ['thread'] };

function firstError(errors: Record<string, string>, fallback: string): string {
    return Object.values(errors)[0] ?? fallback;
}

/**
 * تاسك 100 — المحادثة الداخلية لفاتورة الخدمات، بدل بطاقة الملاحظة الواحدة
 * (تاسك 95). رسائل تتراكم ولا تُستبدل، @ لدورٍ أو لشخص، مرفقات، و«تم الإجراء».
 * تعديل الرسائل وحذفها وإغلاق المحادثة لمدير الفرع والسوبر أدمن وحدهما.
 *
 * الخيط prop مستقلّ لا حقلٌ في الفاتورة، فلا يبلغ حمولة الطباعة أصلاً.
 */
export default function InvoiceThread({ invoiceId, thread }: { invoiceId: number; thread: Thread }) {
    // الاستطلاع يطلب الخيط وحده، وقراءته على الخادم تقدّم موضع القراءة.
    usePoll(30000, { only: ['thread'] });

    const listRef = useRef<HTMLOListElement>(null);
    useEffect(() => {
        listRef.current?.scrollTo({ top: listRef.current.scrollHeight });
    }, [thread.messages.length]);

    function toggleClosed() {
        router.patch(messagesRoute.toggleClosed.url(invoiceId), {}, reload);
    }

    return (
        <Card className="border-amber-300 dark:border-amber-900">
            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2 space-y-0">
                <CardTitle className="flex items-center gap-2 text-base text-amber-900 dark:text-amber-200">
                    <Lock className="size-4" aria-hidden />
                    المحادثة الداخلية
                    <span className="text-xs font-normal text-amber-800/80 dark:text-amber-200/70">— لا تظهر للعميل</span>
                </CardTitle>
                {thread.canModerate && (
                    <Button variant="outline" size="sm" onClick={toggleClosed}>
                        {thread.closedAt ? (
                            <>
                                <LockOpen className="size-4" /> فتح المحادثة
                            </>
                        ) : (
                            <>
                                <Lock className="size-4" /> إغلاق المحادثة
                            </>
                        )}
                    </Button>
                )}
            </CardHeader>
            <CardContent className="space-y-4">
                {thread.messages.length === 0 ? (
                    <p className="text-muted-foreground text-sm">لا رسائل بعد على هذه الفاتورة.</p>
                ) : (
                    <ol ref={listRef} className="max-h-[32rem] space-y-3 overflow-y-auto">
                        {thread.messages.map((message) => (
                            <MessageItem key={message.id} message={message} />
                        ))}
                    </ol>
                )}

                {thread.closedAt && (
                    <p className="rounded-md bg-amber-50 p-2 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                        المحادثة مغلقة{thread.closedByName ? ` — أغلقها ${thread.closedByName}` : ''} في{' '}
                        <span dir="ltr">{formatDateTimeNumeric(thread.closedAt)}</span>
                    </p>
                )}

                {thread.canPost && <Composer invoiceId={invoiceId} mentionables={thread.mentionables} />}
            </CardContent>
        </Card>
    );
}

/** «تم الإجراء» ⟵ «مقروءة» ⟵ «جديدة» — من جهة مرسلها. */
function StatusBadge({ message }: { message: InvoiceMessage }) {
    if (message.deletedAt || message.authorId === null) return null;

    if (message.actionedAt) {
        return (
            <Badge
                variant="outline"
                className="gap-1 border-green-200 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950/40 dark:text-green-300"
                title={message.actionedByName ? `بواسطة ${message.actionedByName}` : undefined}
            >
                <CheckCheck className="size-3" /> تم الإجراء
            </Badge>
        );
    }

    return message.isRead ? (
        <Badge variant="outline" className="text-muted-foreground">
            مقروءة
        </Badge>
    ) : (
        <Badge variant="outline" className="border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-300">
            جديدة
        </Badge>
    );
}

function MessageItem({ message }: { message: InvoiceMessage }) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(message.body ?? '');
    const [saving, setSaving] = useState(false);

    function save() {
        setSaving(true);
        router.patch(
            messagesRoute.update.url(message.id),
            { body: draft },
            {
                ...reload,
                onSuccess: () => setEditing(false),
                onError: (e) => toast.error(firstError(e, 'تعذّر تعديل الرسالة.')),
                onFinish: () => setSaving(false),
            },
        );
    }

    function remove() {
        if (!window.confirm('حذف الرسالة؟ تبقى في المحادثة «محذوفة»، ويُحفظ نصّها في سجل النشاط.')) return;
        router.delete(messagesRoute.destroy.url(message.id), reload);
    }

    function toggleActioned() {
        router.patch(messagesRoute.toggleActioned.url(message.id), {}, reload);
    }

    return (
        <li
            className={cn(
                'rounded-md border p-3',
                message.isNew && 'border-sky-300 bg-sky-50/60 dark:border-sky-900 dark:bg-sky-950/30',
                message.deletedAt && 'bg-muted/40',
            )}
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="font-semibold">{message.authorName}</span>
                    {message.authorRole && (
                        <Badge variant="secondary" className="font-normal">
                            {message.authorRole}
                        </Badge>
                    )}
                    {message.createdAt && (
                        <span className="text-muted-foreground text-xs tabular-nums" dir="ltr">
                            {formatDateTimeNumeric(message.createdAt)}
                        </span>
                    )}
                    <StatusBadge message={message} />
                </div>
                {!editing && (
                    <div className="flex items-center gap-1">
                        {message.canMarkActioned && (
                            <Button variant="ghost" size="sm" className="h-7" onClick={toggleActioned}>
                                <CheckCheck className="size-3.5" /> {message.actionedAt ? 'إلغاء «تم الإجراء»' : 'تم الإجراء'}
                            </Button>
                        )}
                        {message.canModerate && (
                            <>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-7"
                                    onClick={() => {
                                        setDraft(message.body ?? '');
                                        setEditing(true);
                                    }}
                                    aria-label="تعديل الرسالة"
                                >
                                    <Pencil className="size-3.5" />
                                </Button>
                                <Button variant="ghost" size="icon" className="text-destructive size-7" onClick={remove} aria-label="حذف الرسالة">
                                    <Trash2 className="size-3.5" />
                                </Button>
                            </>
                        )}
                    </div>
                )}
            </div>

            {message.deletedAt ? (
                <p className="text-muted-foreground mt-1 text-sm italic">
                    حُذفت{message.deletedByName ? ` بواسطة ${message.deletedByName}` : ''}
                </p>
            ) : editing ? (
                <div className="mt-2 space-y-2">
                    <textarea rows={3} maxLength={2000} value={draft} onChange={(e) => setDraft(e.target.value)} disabled={saving} className={textareaClass} />
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" size="sm" onClick={() => setEditing(false)} disabled={saving}>
                            إلغاء
                        </Button>
                        <Button size="sm" onClick={save} disabled={saving || draft.trim() === ''}>
                            {saving && <Loader2 className="size-4 animate-spin" />} حفظ
                        </Button>
                    </div>
                </div>
            ) : (
                message.body && (
                    <p className="mt-1 text-sm whitespace-pre-wrap">
                        {message.body}
                        {message.editedAt && <span className="text-muted-foreground ms-1 text-xs">(عُدّلت)</span>}
                    </p>
                )
            )}

            {message.attachments.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-2">
                    {message.attachments.map((file) =>
                        file.isImage ? (
                            <a key={file.id} href={file.url} target="_blank" rel="noopener noreferrer" title={file.name}>
                                <img src={file.url} alt={file.name} className="size-20 rounded-md border object-cover" />
                            </a>
                        ) : (
                            <a
                                key={file.id}
                                href={file.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-primary inline-flex max-w-full items-center gap-1 text-sm hover:underline"
                            >
                                <Paperclip className="size-3.5 shrink-0" />
                                <span className="truncate">{file.name}</span>
                            </a>
                        ),
                    )}
                </div>
            )}
        </li>
    );
}

/**
 * خانة الإرسال. «@» يفتح قائمة من يُشار إليه، والاختيار يُدرج «@الاسم» في النص
 * ويضيف رمزه إلى `mentions` — الخادم لا يحلّل النص.
 */
function Composer({ invoiceId, mentionables }: { invoiceId: number; mentionables: Mentionable[] }) {
    const [body, setBody] = useState('');
    const [mentions, setMentions] = useState<Mentionable[]>([]);
    const [files, setFiles] = useState<File[]>([]);
    const [mentionOpen, setMentionOpen] = useState(false);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const fileRef = useRef<HTMLInputElement>(null);

    function insertMention(option: Mentionable) {
        const el = textareaRef.current;
        const caret = el?.selectionStart ?? body.length;
        // «@» الذي فتح القائمة يُستبدل بالاسم لا يُكرَّر.
        const before = body.slice(0, caret).replace(/@$/, '');
        const inserted = `${before}@${option.label} `;
        setBody(inserted + body.slice(caret));
        setMentions((prev) => (prev.some((m) => m.token === option.token) ? prev : [...prev, option]));
        setMentionOpen(false);
        requestAnimationFrame(() => {
            el?.focus();
            el?.setSelectionRange(inserted.length, inserted.length);
        });
    }

    function send() {
        setSending(true);
        router.post(
            messagesRoute.store.url(invoiceId),
            {
                body,
                // إشارةٌ حُذف اسمها من النص قبل الإرسال لا تُنبّه أحداً.
                mentions: mentions.filter((m) => body.includes(`@${m.label}`)).map((m) => m.token),
                attachments: files,
            },
            {
                ...reload,
                forceFormData: true,
                onSuccess: () => {
                    setBody('');
                    setMentions([]);
                    setFiles([]);
                    setError(null);
                },
                onError: (e) => setError(firstError(e, 'تعذّر إرسال الرسالة.')),
                onFinish: () => setSending(false),
            },
        );
    }

    const empty = body.trim() === '' && files.length === 0;

    return (
        <div className="space-y-2">
            <Popover open={mentionOpen} onOpenChange={setMentionOpen}>
                <PopoverAnchor asChild>
                    <textarea
                        ref={textareaRef}
                        rows={3}
                        maxLength={2000}
                        value={body}
                        disabled={sending}
                        placeholder="اكتب رسالة للموظف أو المحاسب أو مدير الفرع — «@» للإشارة إلى أحد"
                        className={textareaClass}
                        onChange={(e) => {
                            setBody(e.target.value);
                            if ((e.nativeEvent as InputEvent).data === '@') setMentionOpen(true);
                        }}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && !empty) send();
                        }}
                    />
                </PopoverAnchor>
                <PopoverContent className="w-64 p-0" align="start">
                    <Command>
                        <CommandInput placeholder="ابحث عن اسم…" />
                        <CommandList>
                            <CommandEmpty>لا توجد نتائج</CommandEmpty>
                            <CommandGroup>
                                {mentionables.map((option) => (
                                    <CommandItem key={option.token} value={option.label} onSelect={() => insertMention(option)}>
                                        <AtSign className="size-3.5" />
                                        {option.label}
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>

            {files.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {files.map((file, i) => (
                        <Badge key={i} variant="secondary" className="max-w-full gap-1 font-normal">
                            <Paperclip className="size-3 shrink-0" />
                            <span className="truncate">{file.name}</span>
                            <button type="button" onClick={() => setFiles((prev) => prev.filter((_, j) => j !== i))} aria-label="إزالة المرفق">
                                <X className="size-3" />
                            </button>
                        </Badge>
                    ))}
                </div>
            )}

            {error && <p className="text-destructive text-xs">{error}</p>}

            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-1">
                    <Button type="button" variant="ghost" size="sm" onClick={() => setMentionOpen(true)} disabled={sending}>
                        <AtSign className="size-4" /> إشارة
                    </Button>
                    <Button type="button" variant="ghost" size="sm" onClick={() => fileRef.current?.click()} disabled={sending || files.length >= 3}>
                        <Paperclip className="size-4" /> إرفاق
                    </Button>
                    <input
                        ref={fileRef}
                        type="file"
                        multiple
                        hidden
                        accept="image/jpeg,image/png,image/webp,application/pdf"
                        onChange={(e) => {
                            const picked = Array.from(e.target.files ?? []);
                            setFiles((prev) => [...prev, ...picked].slice(0, 3));
                            e.target.value = '';
                        }}
                    />
                </div>
                <Button size="sm" onClick={send} disabled={sending || empty}>
                    {sending ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />} إرسال
                </Button>
            </div>
        </div>
    );
}
