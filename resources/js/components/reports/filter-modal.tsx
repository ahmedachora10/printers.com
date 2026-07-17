import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { SlidersHorizontal } from 'lucide-react';
import * as React from 'react';

interface FilterModalProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onApply: () => void;
    onReset: () => void;
    /** Count of applied filters, shown as a badge on the trigger button. */
    activeCount: number;
    /** The filter fields, rendered in a responsive grid inside the dialog. */
    children: React.ReactNode;
    title?: string;
    triggerLabel?: string;
}

/**
 * Reusable filter modal shell for report pages: a trigger button carrying an
 * active-filter count badge, a dialog holding the (page-provided) fields, and a
 * تطبيق / إعادة تعيين footer. Field state lives with the caller (see
 * useReportFilters); this component is presentation only.
 */
export function FilterModal({
    open,
    onOpenChange,
    onApply,
    onReset,
    activeCount,
    children,
    title = 'تصفية النتائج',
    triggerLabel = 'تصفية',
}: FilterModalProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <Button variant="outline" onClick={() => onOpenChange(true)}>
                <SlidersHorizontal className="size-4" />
                {triggerLabel}
                {activeCount > 0 && (
                    <Badge variant="secondary" className="ms-1 rounded-full px-1.5">
                        {activeCount}
                    </Badge>
                )}
            </Button>

            <DialogContent aria-describedby={undefined}>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                </DialogHeader>

                <div className="grid grid-cols-1 gap-4 py-2 sm:grid-cols-2">{children}</div>

                <DialogFooter className="gap-2 sm:gap-2">
                    <Button variant="ghost" onClick={onReset}>
                        إعادة تعيين
                    </Button>
                    <Button onClick={onApply}>تطبيق</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
