import { Button } from '@/components/ui/button';
import { formatCurrency } from '@/lib/utils';
import { Save } from 'lucide-react';

interface PosStickyTotalBarProps {
    /** Invoice grand total, VAT included. */
    total: number;
    /** How many lines are in the cart — the bar reads as a receipt stub, not a bare number. */
    lineCount: number;
    saveLabel: string;
    disabled: boolean;
    onSave: () => void;
}

/**
 * On a phone or a portrait iPad the totals card and the save button sit far
 * below the cart, so the cashier would have to scroll away from the lines to
 * see what the customer owes. This bar pins both to the bottom of the viewport
 * for exactly those widths; from `lg` up the sidebar column is visible beside
 * the cart anyway and the bar disappears.
 *
 * It is `fixed`, not `sticky`, so it needs no wrapping element to be positioned
 * against — the screen using it only has to reserve bottom padding below `lg`.
 */
export function PosStickyTotalBar({ total, lineCount, saveLabel, disabled, onSave }: PosStickyTotalBarProps) {
    return (
        <div className="bg-background/95 supports-[backdrop-filter]:bg-background/85 fixed inset-x-0 bottom-0 z-30 flex items-center gap-3 border-t px-4 py-3 backdrop-blur lg:hidden">
            <div className="min-w-0 flex-1">
                <p className="text-muted-foreground text-[11px]">الإجمالي شامل الضريبة — {lineCount} بند</p>
                <p className="truncate text-lg font-bold tabular-nums">{formatCurrency(total)}</p>
            </div>
            <Button type="button" className="h-11 px-5" disabled={disabled} onClick={onSave}>
                <Save className="size-4" /> {saveLabel}
            </Button>
        </div>
    );
}
