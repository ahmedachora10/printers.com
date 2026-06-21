import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import employeeCommissions from '@/routes/branch-services/employee-commissions';
import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export interface BranchEmployee {
    id: number;
    name: string;
}

export interface EmployeeCommission {
    userId: number;
    commissionPct: number;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    branchServiceId: number | null;
    serviceName: string;
    employees: BranchEmployee[];
    /** Existing per-employee rates for the selected service. */
    current: EmployeeCommission[];
}

// Empty string means "no rate" — the employee earns 0% commission for the service.
type RateMap = Record<number, string>;

function buildRates(employees: BranchEmployee[], current: EmployeeCommission[]): RateMap {
    const byUser = new Map(current.map((c) => [c.userId, c.commissionPct]));
    return Object.fromEntries(employees.map((e) => [e.id, byUser.has(e.id) ? String(byUser.get(e.id)) : '']));
}

export default function BranchServiceEmployeesModal({ open, onOpenChange, branchServiceId, serviceName, employees, current }: Props) {
    const [rates, setRates] = useState<RateMap>(() => buildRates(employees, current));
    const { transform, put, processing } = useForm({});

    // Re-seed the inputs whenever a different service's editor is opened.
    useEffect(() => {
        if (open) setRates(buildRates(employees, current));
    }, [open, branchServiceId, employees, current]);

    function handleSave() {
        if (branchServiceId === null) return;

        transform(() => ({
            commissions: employees.map((e) => {
                const raw = rates[e.id]?.trim() ?? '';
                return {
                    user_id: e.id,
                    commission_pct: raw === '' ? null : Number(raw),
                };
            }),
        }));

        put(employeeCommissions.update(branchServiceId).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>عمولات الموظفين: {serviceName}</DialogTitle>
                    <DialogDescription>النسبة الخاصة بكل موظف لهذه الخدمة. الموظف بدون نسبة يحصل على عمولة 0%.</DialogDescription>
                </DialogHeader>

                <div className="flex-1 space-y-2 overflow-y-auto py-2 pe-1">
                    {employees.length === 0 ? (
                        <p className="text-muted-foreground py-8 text-center text-sm">لا يوجد موظفون في هذا الفرع.</p>
                    ) : (
                        employees.map((e) => (
                            <div key={e.id} className="bg-card flex items-center justify-between gap-3 rounded-lg border px-3 py-2">
                                <span className="min-w-0 truncate text-sm font-medium">{e.name}</span>
                                <div className="flex items-center gap-1.5">
                                    <Input
                                        type="number"
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        className="h-8 w-24 text-sm"
                                        value={rates[e.id] ?? ''}
                                        onChange={(ev) => setRates((prev) => ({ ...prev, [e.id]: ev.target.value }))}
                                        placeholder="0"
                                        dir="ltr"
                                    />
                                    <span className="text-muted-foreground text-sm">%</span>
                                </div>
                            </div>
                        ))
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button onClick={handleSave} disabled={processing || employees.length === 0}>
                        {processing ? 'جاري الحفظ...' : 'حفظ'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
