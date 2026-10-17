import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import serviceCommissions from '@/routes/users/service-commissions';
import { type UserServiceCommission } from '@/types/user';
import { useForm } from '@inertiajs/react';
import { Percent, Search } from 'lucide-react';
import { useState } from 'react';

interface Props {
    userId: number;
    canEdit: boolean;
    services: UserServiceCommission[];
    onSaved?: () => void;
}

// Local rate input keyed by branch service. Empty string means "no rate" — the
// employee earns 0% commission for that service until a rate is set.
type RateMap = Record<number, string>;

function initialRates(services: UserServiceCommission[]): RateMap {
    return Object.fromEntries(services.map((s) => [s.branchServiceId, s.commissionPct === null ? '' : String(s.commissionPct)]));
}

export default function UserServiceCommissionsCard({ userId, canEdit, services, onSaved }: Props) {
    const [rates, setRates] = useState<RateMap>(() => initialRates(services));
    // تاسك 84: التحديد الجماعي — حالةُ عرضٍ خالصة لا تُرسل إلى الخادم أصلاً.
    const [selected, setSelected] = useState<Set<number>>(() => new Set());
    const [bulkRate, setBulkRate] = useState('');
    const [search, setSearch] = useState('');
    const { transform, put, processing } = useForm({});

    // تاسك 188: التحديد والتطبيق الجماعي على الظاهر وحده — وإلا طُبِّق على ما لا يراه المستخدم.
    // الحفظ يبقى يرسل كل الخدمات فلا يضيع ما خارج البحث.
    const term = search.trim().toLowerCase();
    const visible = term ? services.filter((s) => s.serviceName.toLowerCase().includes(term)) : services;
    const selectedIds = visible.map((s) => s.branchServiceId).filter((id) => selected.has(id));
    const allSelected = visible.length > 0 && selectedIds.length === visible.length;
    const someSelected = selectedIds.length > 0 && !allSelected;

    function toggleRow(branchServiceId: number, checked: boolean) {
        setSelected((prev) => {
            const next = new Set(prev);
            if (checked) next.add(branchServiceId);
            else next.delete(branchServiceId);
            return next;
        });
    }

    function toggleAll(checked: boolean) {
        setSelected(checked ? new Set(visible.map((s) => s.branchServiceId)) : new Set());
    }

    /**
     * يملأ الخانات ولا يحفظ: الحفظ يبقى بزرّ «حفظ العمولات» فيرى المستخدم ما
     * سيُحفظ ويستثني ما شاء قبل الإرسال — وتطبيقٌ يحفظ مباشرة يجعل خطأً واحداً
     * يمسّ ثلاث عشرة خدمة دفعةً واحدة.
     */
    function applyRate(ids: number[], value: string) {
        if (ids.length === 0) return;

        setRates((prev) => ({ ...prev, ...Object.fromEntries(ids.map((id) => [id, value])) }));
    }

    function handleSave() {
        transform(() => ({
            commissions: services.map((s) => {
                const raw = rates[s.branchServiceId]?.trim() ?? '';
                return {
                    branch_service_id: s.branchServiceId,
                    // الفراغ يعني null أي «لا صفّ» أي 0% (قاعدة M15)، ولا يُحوَّل
                    // إلى صفرٍ رقميّ: الفرق بينهما هو ما يميّز «لم يُحدَّد» عن
                    // «حُدِّد صفراً».
                    commission_pct: raw === '' ? null : Number(raw),
                };
            }),
        }));

        put(serviceCommissions.update(userId).url, {
            preserveScroll: true,
            onSuccess: () => onSaved?.(),
        });
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

                        <div className="relative mb-3">
                            <Search className="text-muted-foreground pointer-events-none absolute end-3 top-1/2 size-4 -translate-y-1/2" />
                            <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="ابحث باسم الخدمة..." className="pe-9" />
                        </div>

                        {canEdit && (
                            <div className="bg-muted/60 mb-3 flex flex-wrap items-center gap-2 rounded-lg px-3 py-2">
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="svc-comm-select-all"
                                        checked={allSelected ? true : someSelected ? 'indeterminate' : false}
                                        onCheckedChange={(checked) => toggleAll(checked === true)}
                                        className="data-[state=indeterminate]:bg-primary/50 data-[state=indeterminate]:text-primary-foreground"
                                    />
                                    <Label htmlFor="svc-comm-select-all" className="cursor-pointer text-xs whitespace-nowrap">
                                        {selectedIds.length > 0 ? `محدَّد ${selectedIds.length} من ${visible.length}` : 'تحديد الكل'}
                                    </Label>
                                </div>

                                <div className="flex items-center gap-1.5">
                                    <Input
                                        type="number"
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        className="h-8 w-24 text-sm"
                                        value={bulkRate}
                                        onChange={(e) => setBulkRate(e.target.value)}
                                        placeholder="النسبة"
                                        dir="ltr"
                                        aria-label="نسبة العمولة للتطبيق الجماعي"
                                    />
                                    <span className="text-muted-foreground text-sm">%</span>
                                </div>

                                <div className="flex flex-wrap items-center gap-1.5">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="secondary"
                                        onClick={() =>
                                            applyRate(
                                                visible.map((s) => s.branchServiceId),
                                                bulkRate.trim(),
                                            )
                                        }
                                    >
                                        طبّق على الكل
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="secondary"
                                        disabled={selectedIds.length === 0}
                                        onClick={() => applyRate(selectedIds, bulkRate.trim())}
                                    >
                                        طبّق على المحدَّد
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        disabled={selectedIds.length === 0}
                                        onClick={() => applyRate(selectedIds, '')}
                                    >
                                        تفريغ المحدَّد
                                    </Button>
                                </div>
                            </div>
                        )}

                        <div className="space-y-2">
                            {visible.length === 0 && <p className="text-muted-foreground py-6 text-center text-sm">لا خدمة مطابقة للبحث</p>}
                            {visible.map((s) => (
                                <div key={s.branchServiceId} className="bg-muted/40 flex items-center justify-between gap-3 rounded-lg px-3 py-2">
                                    <div className="flex min-w-0 items-center gap-2">
                                        {canEdit && (
                                            <Checkbox
                                                id={`svc-comm-${s.branchServiceId}`}
                                                checked={selected.has(s.branchServiceId)}
                                                onCheckedChange={(checked) => toggleRow(s.branchServiceId, checked === true)}
                                                aria-label={`تحديد ${s.serviceName}`}
                                            />
                                        )}
                                        <Label
                                            htmlFor={canEdit ? `svc-comm-${s.branchServiceId}` : undefined}
                                            className="truncate text-sm font-medium"
                                        >
                                            {s.serviceName}
                                        </Label>
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
                                            aria-label={`نسبة عمولة ${s.serviceName}`}
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
