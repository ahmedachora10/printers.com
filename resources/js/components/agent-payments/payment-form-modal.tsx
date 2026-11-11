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
    /** تاسك 232: فلتر بطاقة المستحق — تبدأ منه الفترة، وتغييرها يعيد تحميل مستحقها. */
    dueFrom: string;
    dueTo: string;
    onPeriodChange: (from: string, to: string) => void;
}

function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

export default function PaymentFormModal({ open, onOpenChange, agent, dueFrom, dueTo, onPeriodChange }: Props) {
    // النافذة تُركَّب من جديد لكل صفّ (key في الصفحة)، فالقيم الابتدائية تكفي.
    const { data, setData, post, processing, errors, reset } = useForm({
        agent_id: agent?.id ?? 0,
        // The settlement covers one branch: an agent may work with several, and
        // each branch pays its own invoices.
        branch_id: agent?.branchId ?? 0,
        period_start: dueFrom,
        period_end: dueTo || todayIso(),
        paid_at: todayIso(),
        notes: '',
    });

    function setPeriod(key: 'period_start' | 'period_end', value: string) {
        setData(key, value);
        const next = { ...data, [key]: value };
        onPeriodChange(next.period_start, next.period_end);
    }

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
                    <DialogTitle>
                        تسجيل دفعة عمولة — {agent?.name}
                        {agent?.branchName && <span className="text-muted-foreground"> · {agent.branchName}</span>}
                    </DialogTitle>
                </DialogHeader>

                <form id="agent-payment-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="rounded-lg border bg-muted/40 px-4 py-2 text-sm">
                        <span className="text-muted-foreground">
                            {dueFrom || dueTo ? 'قيمة الصرف (مستحق الفترة المحددة): ' : 'العمولة المستحقة (إجمالي غير مدفوع): '}
                        </span>
                        <span className="font-bold tabular-nums">{formatCurrency(agent?.outstandingRebate ?? 0)}</span>
                    </div>
                    <p className="text-muted-foreground text-xs">
                        ستُحتسب الدفعة من فواتير هذا الفرع غير المدفوعة ضمن الفترة المحددة فقط.
                    </p>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="ap-start">من تاريخ</Label>
                            <Input
                                id="ap-start"
                                type="date"
                                value={data.period_start}
                                onChange={(e) => setPeriod('period_start', e.target.value)}
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
                                onChange={(e) => setPeriod('period_end', e.target.value)}
                                dir="ltr"
                            />
                            <InputError message={errors.period_end} />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="ap-paid-at">تاريخ الصرف</Label>
                        <Input
                            id="ap-paid-at"
                            type="date"
                            value={data.paid_at}
                            min={data.period_start || undefined}
                            max={todayIso()}
                            onChange={(e) => setData('paid_at', e.target.value)}
                            dir="ltr"
                        />
                        <InputError message={errors.paid_at} />
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
                    <InputError message={errors.branch_id} />
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
