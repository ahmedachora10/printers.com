import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { type ActivityChange, type ActivityEntry } from '@/types/activity';
import { Link } from '@inertiajs/react';

/**
 * تاسك 115: أجزاء الصفّ المشتركة بين شاشتَي سجلّ النشاط — الجدول العامّ والخطّ
 * الزمنيّ لمستخدمٍ واحد. الجملة تُبنى على الخادم (ActivityResource)، وهنا شكلُها.
 */

const AVATAR_TONES = [
    'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
    'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
    'bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
    'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
    'bg-cyan-100 text-cyan-700 dark:bg-cyan-950 dark:text-cyan-300',
];

/** لونٌ ثابتٌ لكل اسم — مجموع أحرفه، فلا يقفز اللون بين الصفحات. */
function tone(name: string): string {
    let sum = 0;
    for (let i = 0; i < name.length; i++) sum += name.charCodeAt(i);
    return AVATAR_TONES[sum % AVATAR_TONES.length];
}

export function ActorAvatar({ name, className }: { name: string; className?: string }) {
    return (
        <span className={cn('flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold', tone(name), className)} aria-hidden>
            {name.trim().charAt(0) || '؟'}
        </span>
    );
}

/** «فلان عدّل فاتورة خدمة [SINV-RYD-12]» — الفاعل اختياريّ في شاشة مستخدمٍ واحد. */
export function ActivitySentence({ entry, showCauser = true }: { entry: ActivityEntry; showCauser?: boolean }) {
    return (
        <span className="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm">
            {showCauser && <span className="font-semibold">{entry.causerName}</span>}
            <span className="text-muted-foreground">{entry.action}</span>
            {entry.subjectType && <span className="text-muted-foreground">{entry.subjectType}</span>}
            {entry.subjectLabel &&
                (entry.subjectUrl ? (
                    <Link href={entry.subjectUrl} className="hover:no-underline">
                        <Badge variant="outline" className="hover:bg-muted font-mono text-xs" dir="ltr">
                            {entry.subjectLabel}
                        </Badge>
                    </Link>
                ) : (
                    <Badge variant="outline" className="font-mono text-xs" dir="ltr">
                        {entry.subjectLabel}
                    </Badge>
                ))}
        </span>
    );
}

/** صندوق الفروق: الحقل، قيمته القديمة ⇐ الجديدة. */
export function ActivityChanges({ changes, max = 6 }: { changes: ActivityChange[]; max?: number }) {
    if (changes.length === 0) return null;

    const shown = changes.slice(0, max);

    return (
        <div className="bg-muted/40 mt-2 space-y-1 rounded-lg border p-3">
            {shown.map((change) => (
                <p key={change.field} className="text-xs">
                    <span className="text-muted-foreground">{change.label}: </span>
                    <span className="text-muted-foreground line-through">{change.old}</span>
                    <span className="text-muted-foreground mx-1">⇐</span>
                    <span className="font-medium">{change.new}</span>
                </p>
            ))}
            {changes.length > shown.length && <p className="text-muted-foreground text-xs">وحقول أخرى ({changes.length - shown.length})</p>}
        </div>
    );
}

/** شارة القسم — المبيعات، المصروفات، الأمان… */
export function LogBadge({ label }: { label: string | null }) {
    if (!label) return null;

    return (
        <Badge variant="secondary" className="shrink-0 text-xs font-normal">
            {label}
        </Badge>
    );
}
