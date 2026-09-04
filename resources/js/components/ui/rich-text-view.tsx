import { cn } from '@/lib/utils';

/**
 * Read-only rendering of markup produced by the rich-text editor. Kept apart
 * from the editor module so pages that only display notes never pull Tiptap
 * into their bundle. The server sanitises the HTML on write
 * (App\Support\HtmlSanitizer), so it is safe to inject here.
 */
export function RichTextView({ html, className }: { html: string; className?: string }) {
    return <div dir="rtl" className={cn('rich-text-content text-sm', className)} dangerouslySetInnerHTML={{ __html: html }} />;
}
