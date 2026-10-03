import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import agentCommissions from '@/routes/branch-services/agent-commissions';
import { type LineAgentCommissionType } from '@/types/pos';
import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export interface BranchAgent {
    id: number;
    name: string;
}

export interface AgentCommission {
    agentId: number;
    type: LineAgentCommissionType;
    value: number;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    branchServiceId: number | null;
    serviceName: string;
    /** خدمة بالمتر المربع/الطولي — وحدها تقبل عمولة «لكل وحدة قياس» */
    measured: boolean;
    agents: BranchAgent[];
    /** Existing per-agent terms for the selected service. */
    current: AgentCommission[];
}

// النوع '' = بلا إعداد — الموظف يكتب العمولة يدوياً في نقطة البيع.
type TermMap = Record<number, { type: LineAgentCommissionType | ''; value: string }>;

/** «لكل وحدة قياس» للخدمة بالمتر وحدها، أو لقيمةٍ محفوظة بها سلفاً. */
export function AgentCommissionTypeSelect({
    value,
    measured,
    onChange,
}: {
    value: LineAgentCommissionType | '';
    measured: boolean;
    onChange: (type: LineAgentCommissionType | '') => void;
}) {
    return (
        <select
            className="border-input bg-background h-8 rounded-md border px-2 text-sm"
            value={value}
            onChange={(e) => onChange(e.target.value as LineAgentCommissionType | '')}
        >
            <option value="">— يكتبها الموظف —</option>
            <option value="percentage">نسبة %</option>
            <option value="fixed">مبلغ ثابت</option>
            {(measured || value === 'per_sqm') && <option value="per_sqm">لكل وحدة قياس</option>}
        </select>
    );
}

function buildTerms(agents: BranchAgent[], current: AgentCommission[]): TermMap {
    const byAgent = new Map(current.map((c) => [c.agentId, c]));
    return Object.fromEntries(
        agents.map((a) => {
            const c = byAgent.get(a.id);
            return [a.id, c ? { type: c.type, value: String(c.value) } : { type: '', value: '' }];
        }),
    );
}

/** تاسك 153 — عمولة كل مندوب على خدمة فرع، على منوال «عمولات الموظفين». */
export default function BranchServiceAgentsModal({ open, onOpenChange, branchServiceId, serviceName, measured, agents, current }: Props) {
    const [terms, setTerms] = useState<TermMap>(() => buildTerms(agents, current));
    const { transform, put, processing, errors } = useForm({});

    // Re-seed the inputs whenever a different service's editor is opened.
    useEffect(() => {
        if (open) setTerms(buildTerms(agents, current));
    }, [open, branchServiceId, agents, current]);

    function setTerm(agentId: number, patch: Partial<TermMap[number]>) {
        setTerms((prev) => ({ ...prev, [agentId]: { ...prev[agentId], ...patch } }));
    }

    function handleSave() {
        if (branchServiceId === null) return;

        transform(() => ({
            commissions: agents.map((a) => {
                const t = terms[a.id];
                return {
                    agent_id: a.id,
                    commission_type: t?.type ? t.type : null,
                    commission_value: t?.type ? Number(t.value || 0) : null,
                };
            }),
        }));

        put(agentCommissions.update(branchServiceId).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    }

    const firstError = Object.values(errors)[0] as string | undefined;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>عمولات المناديب: {serviceName}</DialogTitle>
                    <DialogDescription>
                        العمولة المحدَّدة هنا تُعبَّأ في نقطة البيع ولا يغيّرها الموظف. المندوب بلا إعداد يكتب الموظف عمولته يدوياً.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex-1 space-y-2 overflow-y-auto py-2 pe-1">
                    {agents.length === 0 ? (
                        <p className="text-muted-foreground py-8 text-center text-sm">لا يوجد مناديب مرتبطون بهذا الفرع.</p>
                    ) : (
                        agents.map((a) => {
                            const t = terms[a.id] ?? { type: '', value: '' };
                            return (
                                <div key={a.id} className="bg-card flex flex-wrap items-center justify-between gap-2 rounded-lg border px-3 py-2">
                                    <span className="min-w-0 truncate text-sm font-medium">{a.name}</span>
                                    <div className="flex items-center gap-1.5">
                                        <AgentCommissionTypeSelect value={t.type} measured={measured} onChange={(type) => setTerm(a.id, { type })} />
                                        <Input
                                            type="number"
                                            min="0"
                                            max={t.type === 'percentage' ? 100 : undefined}
                                            step="0.01"
                                            className="h-8 w-24 text-sm"
                                            value={t.value}
                                            disabled={!t.type}
                                            onChange={(e) => setTerm(a.id, { value: e.target.value })}
                                            placeholder="0"
                                            dir="ltr"
                                        />
                                    </div>
                                </div>
                            );
                        })
                    )}
                </div>

                {firstError && <p className="text-destructive text-sm">{firstError}</p>}

                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button onClick={handleSave} disabled={processing || agents.length === 0}>
                        {processing ? 'جاري الحفظ...' : 'حفظ'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
