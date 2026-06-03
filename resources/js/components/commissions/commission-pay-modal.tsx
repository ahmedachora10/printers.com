import { pay } from '@/routes/commissions';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCurrency } from '@/lib/utils';
import { type CommissionEmployeeRow } from '@/types/commission';
import { useForm } from '@inertiajs/react';
import InputError from '../input-error';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employee: CommissionEmployeeRow | null;
}

function monthStart(): string {
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth(), 1).toLocaleDateString('en-CA');
}

function monthEnd(): string {
    const now = new Date();
    return new Date(now.getFullYear(), now.getMonth() + 1, 0).toLocaleDateString('en-CA');
}

export default function CommissionPayModal({ open, onOpenChange, employee }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm({
        user_id: employee?.userId ?? 0,
        period_start: monthStart(),
        period_end: monthEnd(),
        notes: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        post(pay.url(), {
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
                    <DialogTitle>صرف عمولة — {employee?.userName}</DialogTitle>
                </DialogHeader>

                <form id="commission-pay-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="rounded-lg border bg-muted/40 p-3 text-sm">
                        <div className="flex items-center justify-between">
                            <span className="text-muted-foreground">العمولة المستحقة (غير المصروفة)</span>
                            <span className="font-semibold text-amber-600">
                                {formatCurrency(employee?.pending ?? 0)}
                            </span>
                        </div>
                        <p className="mt-2 text-xs text-muted-foreground">
                            سيتم صرف جميع العمولات غير المدفوعة المكتسبة خلال الفترة المحددة أدناه.
                        </p>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="period-start">من تاريخ</Label>
                            <Input
                                id="period-start"
                                type="date"
                                value={data.period_start}
                                onChange={(e) => setData('period_start', e.target.value)}
                                dir="ltr"
                            />
                            <InputError message={errors.period_start} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="period-end">إلى تاريخ</Label>
                            <Input
                                id="period-end"
                                type="date"
                                value={data.period_end}
                                onChange={(e) => setData('period_end', e.target.value)}
                                dir="ltr"
                            />
                            <InputError message={errors.period_end} />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="notes">ملاحظات (اختياري)</Label>
                        <textarea
                            id="notes"
                            rows={3}
                            value={data.notes}
                            onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setData('notes', e.target.value)}
                            placeholder="ملاحظات حول عملية الصرف..."
                            className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        />
                        <InputError message={errors.notes} />
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="commission-pay-form" disabled={processing}>
                        {processing ? 'جاري الصرف...' : 'تأكيد الصرف'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
