import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import agentCommissions from '@/routes/users/agent-commissions';
import { type LineAgentCommissionType } from '@/types/pos';
import { type ManagedUser } from '@/types/user';
import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface AgentServiceCommission {
    branchServiceId: number;
    branchName: string | null;
    serviceName: string;
    /** خدمة بالمتر المربع/الطولي — وحدها تقبل «لكل وحدة قياس» */
    measured: boolean;
    type: LineAgentCommissionType | null;
    value: number | null;
}

// النوع '' = بلا إعداد — الموظف يكتب العمولة يدوياً في نقطة البيع.
type Term = { type: LineAgentCommissionType | ''; value: string };

interface Props {
    user: ManagedUser | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

function TypeSelect({ value, measured, onChange }: { value: Term['type']; measured: boolean; onChange: (t: Term['type']) => void }) {
    return (
        <select
            className="border-input bg-background h-8 rounded-md border px-2 text-sm"
            value={value}
            onChange={(e) => onChange(e.target.value as Term['type'])}
        >
            <option value="">— يكتبها الموظف —</option>
            <option value="percentage">نسبة %</option>
            <option value="fixed">مبلغ ثابت</option>
            {(measured || value === 'per_sqm') && <option value="per_sqm">لكل وحدة قياس</option>}
        </select>
    );
}

/**
 * عمولة المندوب على كل خدمة في فروعه — على منوال «عمولات الخدمات» للموظف،
 * والجدول نفسه (agent_services) الذي تكتبه نافذة المناديب في صفحة الخدمات.
 */
export default function AgentServiceCommissionsModal({ user, open, onOpenChange }: Props) {
    const [services, setServices] = useState<AgentServiceCommission[] | null>(null);
    const [terms, setTerms] = useState<Record<number, Term>>({});
    const [selected, setSelected] = useState<Set<number>>(() => new Set());
    const [bulk, setBulk] = useState<Term>({ type: 'percentage', value: '' });
    const { transform, put, processing, errors } = useForm({});

    useEffect(() => {
        if (!open || !user) return;

        let cancelled = false;
        setServices(null);
        setSelected(new Set());

        fetch(agentCommissions.show(user.id).url, { headers: { Accept: 'application/json' } })
            .then((res) => res.json())
            .then((data) => {
                if (cancelled) return;
                const rows: AgentServiceCommission[] = data.agentCommissions ?? [];
                setServices(rows);
                setTerms(Object.fromEntries(rows.map((s) => [s.branchServiceId, { type: s.type ?? '', value: s.value === null ? '' : String(s.value) }])));
            })
            .catch(() => {
                if (!cancelled) setServices([]);
            });

        return () => {
            cancelled = true;
        };
    }, [open, user]);

    const rows = services ?? [];
    const multiBranch = new Set(rows.map((s) => s.branchName)).size > 1;
    const allSelected = rows.length > 0 && selected.size === rows.length;

    function setTerm(id: number, patch: Partial<Term>) {
        setTerms((prev) => ({ ...prev, [id]: { ...prev[id], ...patch } }));
    }

    function toggleRow(id: number, checked: boolean) {
        setSelected((prev) => {
            const next = new Set(prev);
            if (checked) next.add(id);
            else next.delete(id);
            return next;
        });
    }

    // يملأ الخانات ولا يحفظ (تاسك 84). «لكل وحدة قياس» تتخطّى الخدمة بالقطعة.
    function apply(targets: AgentServiceCommission[], term: Term) {
        const eligible = targets.filter((s) => term.type !== 'per_sqm' || s.measured);
        setTerms((prev) => ({ ...prev, ...Object.fromEntries(eligible.map((s) => [s.branchServiceId, term])) }));
    }

    const selectedRows = () => rows.filter((s) => selected.has(s.branchServiceId));

    function handleSave() {
        if (!user) return;

        transform(() => ({
            commissions: rows.map((s) => {
                const t = terms[s.branchServiceId];
                return {
                    branch_service_id: s.branchServiceId,
                    commission_type: t?.type ? t.type : null,
                    commission_value: t?.type ? Number(t.value || 0) : null,
                };
            }),
        }));

        put(agentCommissions.update(user.id).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    }

    const firstError = Object.values(errors)[0] as string | undefined;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{user?.name ?? 'عمولات المندوب'}</DialogTitle>
                    <DialogDescription>
                        العمولة المحدَّدة هنا تُعبَّأ في نقطة البيع ولا يغيّرها الموظف. الخدمة بلا إعداد يكتب الموظف عمولتها يدوياً.
                    </DialogDescription>
                </DialogHeader>

                {services === null ? (
                    <p className="text-muted-foreground py-8 text-center text-sm">جاري التحميل...</p>
                ) : rows.length === 0 ? (
                    <p className="text-muted-foreground py-8 text-center text-sm">لا توجد خدمات نشطة في فروع هذا المندوب.</p>
                ) : (
                    <>
                        <div className="bg-muted/60 flex flex-wrap items-center gap-2 rounded-lg px-3 py-2">
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="agent-comm-select-all"
                                    checked={allSelected ? true : selected.size > 0 ? 'indeterminate' : false}
                                    onCheckedChange={(c) => setSelected(c === true ? new Set(rows.map((s) => s.branchServiceId)) : new Set())}
                                />
                                <Label htmlFor="agent-comm-select-all" className="cursor-pointer text-xs whitespace-nowrap">
                                    {selected.size > 0 ? `محدَّد ${selected.size} من ${rows.length}` : 'تحديد الكل'}
                                </Label>
                            </div>
                            <TypeSelect value={bulk.type} measured onChange={(type) => setBulk((b) => ({ ...b, type }))} />
                            <Input
                                type="number"
                                min="0"
                                step="0.01"
                                className="h-8 w-24 text-sm"
                                value={bulk.value}
                                disabled={!bulk.type}
                                onChange={(e) => setBulk((b) => ({ ...b, value: e.target.value }))}
                                placeholder="القيمة"
                                dir="ltr"
                                aria-label="قيمة العمولة للتطبيق الجماعي"
                            />
                            <Button type="button" size="sm" variant="secondary" onClick={() => apply(rows, bulk)}>
                                طبّق على الكل
                            </Button>
                            <Button type="button" size="sm" variant="secondary" disabled={selected.size === 0} onClick={() => apply(selectedRows(), bulk)}>
                                طبّق على المحدَّد
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                disabled={selected.size === 0}
                                onClick={() => apply(selectedRows(), { type: '', value: '' })}
                            >
                                تفريغ المحدَّد
                            </Button>
                        </div>

                        <div className="flex-1 space-y-2 overflow-y-auto py-2 pe-1">
                            {rows.map((s, i) => {
                                const t = terms[s.branchServiceId] ?? { type: '', value: '' };
                                return (
                                    <div key={s.branchServiceId}>
                                        {multiBranch && s.branchName !== rows[i - 1]?.branchName && (
                                            <p className="text-muted-foreground mt-3 mb-1 text-xs font-semibold">{s.branchName}</p>
                                        )}
                                        <div className="bg-muted/40 flex flex-wrap items-center justify-between gap-2 rounded-lg px-3 py-2">
                                            <div className="flex min-w-0 items-center gap-2">
                                                <Checkbox
                                                    id={`agent-comm-${s.branchServiceId}`}
                                                    checked={selected.has(s.branchServiceId)}
                                                    onCheckedChange={(c) => toggleRow(s.branchServiceId, c === true)}
                                                />
                                                <Label htmlFor={`agent-comm-${s.branchServiceId}`} className="truncate text-sm font-medium">
                                                    {s.serviceName}
                                                </Label>
                                            </div>
                                            <div className="flex items-center gap-1.5">
                                                <TypeSelect value={t.type} measured={s.measured} onChange={(type) => setTerm(s.branchServiceId, { type })} />
                                                <Input
                                                    type="number"
                                                    min="0"
                                                    max={t.type === 'percentage' ? 100 : undefined}
                                                    step="0.01"
                                                    className="h-8 w-24 text-sm"
                                                    value={t.value}
                                                    disabled={!t.type}
                                                    onChange={(e) => setTerm(s.branchServiceId, { value: e.target.value })}
                                                    placeholder="0"
                                                    dir="ltr"
                                                    aria-label={`عمولة ${s.serviceName}`}
                                                />
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </>
                )}

                {firstError && <p className="text-destructive text-sm">{firstError}</p>}

                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button onClick={handleSave} disabled={processing || rows.length === 0}>
                        {processing ? 'جاري الحفظ...' : 'حفظ'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
