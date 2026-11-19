import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type PriceTier, tierRangeLabel } from '@/lib/invoice';
import { Plus, Trash2 } from 'lucide-react';

/**
 * تاسك 241 — شرائح السعر حسب الكمية، يشاركه نموذجا الخدمة والمنتج. «من» محسوبة من «إلى»
 * السابقة، و«إلى» الفارغة في الأخيرة = «أكثر من». السعر يسري على كامل كمية السطر.
 */
export default function PriceTiersField({
    value,
    onChange,
    unit,
    errors,
    showBounds = false,
}: {
    value: PriceTier[];
    onChange: (tiers: PriceTier[]) => void;
    /** وحدة الكمية: «قطعة» أو «م²» أو «م». */
    unit: string;
    /** أخطاء الخادم: price_tiers و price_tiers.N.* */
    errors: Partial<Record<string, string>>;
    /** أقل/أعلى سعر لكل شريحة — للخدمة وحدها (تُلزم الموظف). */
    showBounds?: boolean;
}) {
    const num = (v: string) => (v === '' ? null : Math.max(0, parseFloat(v) || 0));
    const set = (i: number, patch: Partial<PriceTier>) => onChange(value.map((t, j) => (j === i ? { ...t, ...patch } : t)));
    const rowErrors = (i: number) =>
        Object.entries(errors)
            .filter(([k]) => k.startsWith(`price_tiers.${i}.`))
            .map(([, m]) => m);

    return (
        <div className="space-y-2 rounded-md border p-3">
            <div className="flex items-center justify-between gap-2">
                <div>
                    <Label>شرائح التسعير حسب الكمية</Label>
                    <p className="text-muted-foreground text-xs">
                        سعر الشريحة التي بلغتها كمية السطر يسري على الكمية كلها. اترك «إلى» فارغة في الأخيرة لتعني «أكثر من».
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() => onChange([...value, { to: null, price: 0, min_price: null, max_price: null }])}
                >
                    <Plus className="size-3.5" /> شريحة
                </Button>
            </div>

            {value.map((tier, i) => (
                <div key={i} className="space-y-1">
                    <div className="flex flex-wrap items-end gap-2">
                        <span className="text-muted-foreground w-full text-xs sm:w-28">{tierRangeLabel(value, i)}</span>
                        <div className="w-24">
                            <Label className="text-xs">إلى ({unit})</Label>
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                dir="ltr"
                                value={tier.to ?? ''}
                                onChange={(e) => set(i, { to: num(e.target.value) })}
                            />
                        </div>
                        <div className="w-24">
                            <Label className="text-xs">السعر</Label>
                            <Input
                                type="number"
                                step="0.01"
                                min="0"
                                dir="ltr"
                                value={tier.price || ''}
                                onChange={(e) => set(i, { price: num(e.target.value) ?? 0 })}
                            />
                        </div>
                        {showBounds && (
                            <>
                                <div className="w-24">
                                    <Label className="text-xs">أقل سعر</Label>
                                    <Input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        dir="ltr"
                                        value={tier.min_price ?? ''}
                                        onChange={(e) => set(i, { min_price: num(e.target.value) })}
                                    />
                                </div>
                                <div className="w-24">
                                    <Label className="text-xs">أعلى سعر</Label>
                                    <Input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        dir="ltr"
                                        value={tier.max_price ?? ''}
                                        onChange={(e) => set(i, { max_price: num(e.target.value) })}
                                    />
                                </div>
                            </>
                        )}
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            aria-label="حذف الشريحة"
                            onClick={() => onChange(value.filter((_, j) => j !== i))}
                        >
                            <Trash2 className="text-destructive size-3.5" />
                        </Button>
                    </div>
                    {rowErrors(i).map((m) => (
                        <InputError key={m} message={m} />
                    ))}
                </div>
            ))}

            {showBounds && value.length > 0 && (
                <p className="text-muted-foreground text-xs">أقل/أعلى سعر يُلزمان الموظف وحده؛ الفارغ يرث حدّي الخدمة أعلاه.</p>
            )}
            <InputError message={errors.price_tiers} />
        </div>
    );
}
