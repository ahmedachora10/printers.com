import { store } from '@/actions/App/Http/Controllers/AgentPaymentController';
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
import { type AgentOutstanding } from '@/types/agent-payment';
import { useForm } from '@inertiajs/react';
import InputError from '../input-error';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    agent: AgentOutstanding | null;
}

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

export default function PaymentFormModal({ open, onOpenChange, agent }: Props) {
    const { data, setData, post, processing, errors, reset } = useForm({
        agent_id: agent?.id ?? 0,
        period_start: '',
        period_end: todayIso(),
        notes: '',
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        post(store.url(), {
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
                    <DialogTitle>تسجيل دفعة عمولة — {agent?.name}</DialogTitle>
                </DialogHeader>

                <form id="agent-payment-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="rounded-lg border bg-muted/40 px-4 py-2 text-sm">
                        <span className="text-muted-foreground">العمولة المستحقة (إجمالي غير مدفوع): </span>
                        <span className="font-bold tabular-nums">{formatCurrency(agent?.outstandingRebate ?? 0)}</span>
                    </div>
                    <p className="text-muted-foreground text-xs">
                        ستُحتسب الدفعة من الفواتير غير المدفوعة ضمن الفترة المحددة فقط.
                    </p>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="ap-start">من تاريخ</Label>
                            <Input
                                id="ap-start"
                                type="date"
                                value={data.period_start}
                                onChange={(e) => setData('period_start', e.target.value)}
                                dir="ltr"
                            />
                            <InputError message={errors.period_start} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="ap-end">إلى تاريخ</Label>
                            <Input
                                id="ap-end"
                                type="date"
                                value={data.period_end}
                                onChange={(e) => setData('period_end', e.target.value)}
                                dir="ltr"
                            />
                            <InputError message={errors.period_end} />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="ap-notes">ملاحظات</Label>
                        <textarea
                            id="ap-notes"
                            rows={2}
                            value={data.notes}
                            onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setData('notes', e.target.value)}
                            placeholder="اختياري"
                            className="flex min-h-[64px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        />
                        <InputError message={errors.notes} />
                    </div>
                    <InputError message={errors.agent_id} />
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="agent-payment-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : 'تسجيل الدفعة'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
