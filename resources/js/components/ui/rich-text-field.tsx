import { cn } from '@/lib/utils';
import { lazy, Suspense } from 'react';

// Tiptap and ProseMirror weigh ~400 kB, which no page should carry just to
// render a list. Loading the editor on demand keeps that off the first paint.
const RichTextEditor = lazy(() => import('./rich-text-editor'));

interface Props {
    value: string;
    onChange: (html: string) => void;
    id?: string;
    placeholder?: string;
    disabled?: boolean;
    className?: string;
}

/**
 * The rich-text form field. Renders a same-sized placeholder frame while the
 * editor chunk downloads, so nothing on the form jumps when it arrives.
 */
export function RichTextField(props: Props) {
    return (
        <Suspense fallback={<EditorSkeleton className={props.className} />}>
            <RichTextEditor {...props} />
        </Suspense>
    );
}

function EditorSkeleton({ className }: { className?: string }) {
    return (
        <div className={cn('border-input bg-background overflow-hidden rounded-md border', className)}>
            <div className="bg-muted/40 border-input h-10 border-b" />
            <div className="min-h-32" />
        </div>
    );
}
