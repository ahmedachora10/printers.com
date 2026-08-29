import { pay } from '@/actions/App/Http/Controllers/IncentiveController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { formatCurrency } from '@/lib/utils';
import { type IncentivePlan } from '@/types/incentive';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import InputError from '../input-error';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    plan: IncentivePlan | null;
}

export default function PayBonusModal({ open, onOpenChange, plan }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm({ notes: '' });

    useEffect(() => {
        reset();
    }, [plan, open]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (!plan) return;
        post(pay.url(plan), {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                reset();
            },
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>صرف المكافأة — {plan?.userName}</DialogTitle>
                </DialogHeader>

                <form id="pay-bonus-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="space-y-2 rounded-lg border bg-muted/40 px-4 py-3 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">الفترة</span>
                            <span className="tabular-nums" dir="ltr">{plan?.periodLabel}</span>
                        </div>
                        {/* الهدف بجانب المحقّق: النسبة تُقاس على الهدف (تاسك 73)، فيرى الصارف من أين جاء الرقم. */}
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">الهدف</span>
                            <span className="tabular-nums">{formatCurrency(plan?.targetAmount ?? 0)}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">المبيعات المحققة</span>
                            <span className="tabular-nums">{formatCurrency(plan?.achievedAmount ?? 0)}</span>
                        </div>
                        <div className="flex justify-between border-t pt-2 font-semibold">
                            <span>قيمة المكافأة</span>
                            <span className="tabular-nums">{formatCurrency(plan?.bonusAmount ?? 0)}</span>
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="bonus-notes">ملاحظات</Label>
                        <textarea
                            id="bonus-notes"
                            rows={2}
                            value={data.notes}
                            onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setData('notes', e.target.value)}
                            placeholder="اختياري"
                            className="flex min-h-[64px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        />
                        <InputError message={errors.notes} />
                        <InputError message={(errors as Record<string, string>).incentive_plan_id} />
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="pay-bonus-form" disabled={processing}>
                        {processing ? 'جاري الصرف...' : 'تأكيد الصرف'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
