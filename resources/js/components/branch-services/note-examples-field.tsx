import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';

/** Mirrors the server cap in HandlesNoteExamples. */
export const MAX_NOTE_EXAMPLES = 10;

/** How the phrases read once joined into the POS placeholder. */
export function noteExamplesPlaceholder(examples: string[]): string | null {
    return examples.length > 0 ? examples.join(' — ') : null;
}

interface Props {
    value: string[];
    onChange: (next: string[]) => void;
    /** validation message for `note_examples` or any `note_examples.*` entry */
    error?: string;
    /** tighter spacing for the inline super-admin form */
    compact?: boolean;
    idPrefix?: string;
}

/**
 * Ready-made detail phrases for a branch service ("طباعة وجهين", "تغليف حراري").
 * They never reach the invoice on their own — the service POS joins them into
 * the placeholder of that line's detail box so the cashier sees what is usually
 * written for this service.
 */
export default function NoteExamplesField({ value, onChange, error, compact = false, idPrefix = 'bs' }: Props) {
    const [draft, setDraft] = useState('');
    const atCap = value.length >= MAX_NOTE_EXAMPLES;

    function addDraft() {
        const phrase = draft.trim();
        if (phrase === '' || atCap || value.includes(phrase)) {
            setDraft('');
            return;
        }
        onChange([...value, phrase]);
        setDraft('');
    }

    return (
        <div className="space-y-1.5">
            <Label className={compact ? 'text-xs' : undefined} htmlFor={`${idPrefix}-note-example`}>
                أمثلة التفاصيل
            </Label>
            <p className="text-muted-foreground text-xs">تظهر للموظف كنص إرشادي داخل خانة «تفاصيل إضافية للخدمة» في نقطة البيع.</p>

            {value.length > 0 && (
                <ul className="space-y-1">
                    {value.map((example, index) => (
                        <li key={example} className="bg-muted/40 flex items-center justify-between gap-2 rounded-md border px-2 py-1">
                            <span className="text-sm">{example}</span>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                className="text-muted-foreground hover:text-destructive h-6 w-6 p-0"
                                aria-label={`حذف المثال ${example}`}
                                onClick={() => onChange(value.filter((_, i) => i !== index))}
                            >
                                <X className="size-3.5" />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            <div className="flex gap-2">
                <Input
                    id={`${idPrefix}-note-example`}
                    value={draft}
                    maxLength={120}
                    disabled={atCap}
                    placeholder={atCap ? `الحد الأقصى ${MAX_NOTE_EXAMPLES} أمثلة` : 'مثال: طباعة وجهين'}
                    className={compact ? 'h-8 text-sm' : undefined}
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => {
                        // Enter adds a phrase instead of submitting the whole form.
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            addDraft();
                        }
                    }}
                />
                <Button type="button" variant="outline" size={compact ? 'sm' : 'default'} disabled={atCap || draft.trim() === ''} onClick={addDraft}>
                    <Plus className="size-3.5" />
                    إضافة
                </Button>
            </div>

            <InputError message={error} />
        </div>
    );
}
