import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import serviceCommissions from '@/routes/users/service-commissions';
import { type UserServiceCommission } from '@/types/user';
import { useForm } from '@inertiajs/react';
import { Percent } from 'lucide-react';
import { useState } from 'react';

interface Props {
    userId: number;
    canEdit: boolean;
    services: UserServiceCommission[];
}

// Local rate input keyed by branch service. Empty string means "no rate" — the
// employee earns 0% commission for that service until a rate is set.
type RateMap = Record<number, string>;

function initialRates(services: UserServiceCommission[]): RateMap {
    return Object.fromEntries(services.map((s) => [s.branchServiceId, s.commissionPct === null ? '' : String(s.commissionPct)]));
}

export default function UserServiceCommissionsCard({ userId, canEdit, services }: Props) {
    const [rates, setRates] = useState<RateMap>(() => initialRates(services));
    const { transform, put, processing } = useForm({});

    function handleSave() {
        transform(() => ({
            commissions: services.map((s) => {
                const raw = rates[s.branchServiceId]?.trim() ?? '';
                return {
                    branch_service_id: s.branchServiceId,
                    commission_pct: raw === '' ? null : Number(raw),
                };
            }),
        }));

        put(serviceCommissions.update(userId).url, { preserveScroll: true });
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Percent className="size-5" />
                    عمولات الخدمات
                </CardTitle>
            </CardHeader>
            <CardContent>
                {services.length === 0 ? (
                    <p className="text-muted-foreground py-6 text-center text-sm">لا توجد خدمات نشطة في هذا الفرع</p>
                ) : (
                    <>
                        <p className="text-muted-foreground mb-3 text-xs">النسبة الخاصة بهذا الموظف لكل خدمة. الخدمة بدون نسبة تعني عمولة 0%.</p>
                        <div className="space-y-2">
                            {services.map((s) => (
                                <div key={s.branchServiceId} className="bg-muted/40 flex items-center justify-between gap-3 rounded-lg px-3 py-2">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">{s.serviceName}</p>
                                    </div>
                                    <div className="flex items-center gap-1.5">
                                        <Input
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.01"
                                            className="h-8 w-24 text-sm"
                                            value={rates[s.branchServiceId] ?? ''}
                                            onChange={(e) => setRates((prev) => ({ ...prev, [s.branchServiceId]: e.target.value }))}
                                            placeholder="0"
                                            dir="ltr"
                                            disabled={!canEdit}
                                        />
                                        <span className="text-muted-foreground text-sm">%</span>
                                    </div>
                                </div>
                            ))}
                        </div>

                        {canEdit && (
                            <div className="mt-4 flex justify-end">
                                <Button size="sm" onClick={handleSave} disabled={processing}>
                                    {processing ? 'جاري الحفظ...' : 'حفظ العمولات'}
                                </Button>
                            </div>
                        )}
                    </>
                )}
            </CardContent>
        </Card>
    );
}
