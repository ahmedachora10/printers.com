/**
 * The invoice-level remark for the customer, rendered under the lines table.
 *
 * Deliberately unlike the per-line detail (تاسك 5), which is muted 10px text
 * tucked under the item name: this one is a labelled, framed block at normal
 * size, so a reader can never mistake a note about the whole order for a note
 * about one item.
 */
export default function InvoiceNotes({ notes, variant = 'screen' }: { notes: string | null | undefined; variant?: 'screen' | 'a4' | 'thermal' }) {
    if (!notes || notes.trim() === '') return null;

    if (variant === 'thermal') {
        return (
            <div className="mt-3 border border-dashed border-black p-2 text-xs">
                <p className="mb-0.5 font-bold">ملاحظات</p>
                <p className="whitespace-pre-line">{notes}</p>
            </div>
        );
    }

    if (variant === 'a4') {
        return (
            <div className="mt-4 border border-neutral-400 p-3 text-sm">
                <p className="mb-1 font-bold">ملاحظات</p>
                <p className="whitespace-pre-line">{notes}</p>
            </div>
        );
    }

    return (
        <div className="bg-muted/40 mt-4 rounded-md border p-3">
            <p className="mb-1 text-sm font-semibold">ملاحظات الفاتورة</p>
            <p className="text-sm whitespace-pre-line">{notes}</p>
        </div>
    );
}
