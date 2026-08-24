import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import materials from '@/routes/branch-services/materials';
import { type BranchProductOption, type BranchServiceMaterial } from '@/types/branch-service';
import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    branchServiceId: number | null;
    serviceName: string;
    /** هل الخدمة مسعّرة بالمتر المربع — يغيّر معنى «لكل وحدة» في النص المساعد */
    isSqmService: boolean;
    products: BranchProductOption[];
    current: BranchServiceMaterial[];
}

/** سطر قيد التحرير — الأرقام نصٌّ لتبقى الحقول فارغة حتى يكتب المستخدم. */
interface DraftRow {
    productId: string;
    qtyPerUnit: string;
    wastePct: string;
}

/** رقمان بعد الفاصلة، بلا أصفار عائدة — كما يعرضها الخادم. */
function round2(value: number): number {
    return Math.round(value * 100) / 100;
}

function buildRows(current: BranchServiceMaterial[]): DraftRow[] {
    return current.map((m) => ({
        productId: String(m.productId),
        qtyPerUnit: String(m.qtyPerUnit),
        // الصفر يُترك فارغاً: «بلا هالك» أوضح من «0» في خانةٍ نادراً ما تُملأ.
        wastePct: m.wastePct > 0 ? String(m.wastePct) : '',
    }));
}

/**
 * تعريف خامات المخزون التي تستهلكها الخدمة (تاسك 50). ليست تكلفة الخامات
 * المحاسبية — تلك مبلغ يُخصم من عمولة الموظف، وهذه منتجات تُخصم من المخزون
 * كمياتُها عند اعتماد الفاتورة وتعود إليه عند استرجاعها.
 */
export default function BranchServiceMaterialsModal({
    open,
    onOpenChange,
    branchServiceId,
    serviceName,
    isSqmService,
    products,
    current,
}: Props) {
    const [rows, setRows] = useState<DraftRow[]>(() => buildRows(current));
    const { transform, put, processing, errors } = useForm({});

    // Re-seed whenever a different service's editor is opened.
    useEffect(() => {
        if (open) setRows(buildRows(current));
    }, [open, branchServiceId, current]);

    const chosenIds = new Set(rows.map((r) => r.productId).filter(Boolean));
    const unitLabel = isSqmService ? 'لكل م² مبيع' : 'لكل قطعة';

    function updateRow(index: number, patch: Partial<DraftRow>) {
        setRows((prev) => prev.map((r, i) => (i === index ? { ...r, ...patch } : r)));
    }

    function handleSave() {
        if (branchServiceId === null) return;

        transform(() => ({
            materials: rows
                .filter((r) => r.productId !== '' && Number(r.qtyPerUnit) > 0)
                .map((r) => ({
                    product_id: Number(r.productId),
                    qty_per_unit: Number(r.qtyPerUnit),
                    waste_pct: r.wastePct === '' ? 0 : Number(r.wastePct),
                })),
        }));

        put(materials.update(branchServiceId).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    }

    // الخادم يردّ أخطاء الأسطر بمفاتيح materials.0.product_id — تُجمع في سطر واحد
    // فوق الجدول بدل تعليقها على حقول قد يكون ترتيبها تغيّر.
    const rowErrors = Object.entries(errors as Record<string, string>)
        .filter(([key]) => key.startsWith('materials'))
        .map(([, message]) => message);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>خامات المخزون: {serviceName}</DialogTitle>
                    <DialogDescription>
                        المنتجات التي تستهلكها الخدمة من المخزون. تُخصم عند اعتماد الفاتورة وتعود عند استرجاعها — والكمية {unitLabel}.
                    </DialogDescription>
                </DialogHeader>

                {rowErrors.length > 0 && (
                    <div className="bg-destructive/10 text-destructive space-y-1 rounded-md p-2 text-xs">
                        {rowErrors.map((message) => (
                            <p key={message}>{message}</p>
                        ))}
                    </div>
                )}

                <div className="flex-1 space-y-2 overflow-y-auto py-2 pe-1">
                    {products.length === 0 ? (
                        <p className="text-muted-foreground py-8 text-center text-sm">لا توجد منتجات نشطة في هذا الفرع لربطها كخامة.</p>
                    ) : rows.length === 0 ? (
                        <p className="text-muted-foreground py-8 text-center text-sm">لا خامات مخزون على هذه الخدمة — أضف واحدة بالأسفل.</p>
                    ) : (
                        rows.map((row, index) => {
                            const product = products.find((p) => String(p.id) === row.productId);
                            // ما يقع في المخزون فعلاً — نفس صيغة الخادم: الكمية زائد الهالك.
                            const effective = round2(Number(row.qtyPerUnit || 0) * (1 + Number(row.wastePct || 0) / 100));

                            return (
                                <div key={index} className="bg-card grid gap-2 rounded-lg border p-3 sm:grid-cols-[1fr_8rem_7rem_auto] sm:items-end">
                                    <div className="space-y-1.5">
                                        <label className="text-muted-foreground block text-[11px] font-medium">الخامة</label>
                                        <Select value={row.productId} onValueChange={(v) => updateRow(index, { productId: v })}>
                                            <SelectTrigger className="h-9 w-full">
                                                <SelectValue placeholder="اختر منتجاً" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {products.map((p) => (
                                                    <SelectItem
                                                        key={p.id}
                                                        value={String(p.id)}
                                                        disabled={String(p.id) !== row.productId && chosenIds.has(String(p.id))}
                                                    >
                                                        {p.name} — {p.sku}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-1.5">
                                        <label className="text-muted-foreground block text-[11px] font-medium">
                                            الكمية {unitLabel}
                                            {product?.unitName ? ` (${product.unitName})` : ''}
                                        </label>
                                        <Input
                                            type="number"
                                            min="0.01"
                                            step="0.01"
                                            className="h-9 text-center"
                                            value={row.qtyPerUnit}
                                            onChange={(e) => updateRow(index, { qtyPerUnit: e.target.value })}
                                            placeholder="1"
                                            dir="ltr"
                                        />
                                    </div>

                                    <div className="space-y-1.5">
                                        <label className="text-muted-foreground block text-[11px] font-medium">الهالك %</label>
                                        <Input
                                            type="number"
                                            min="0"
                                            max="100"
                                            step="0.5"
                                            className="h-9 text-center"
                                            value={row.wastePct}
                                            onChange={(e) => updateRow(index, { wastePct: e.target.value })}
                                            placeholder="0"
                                            dir="ltr"
                                        />
                                    </div>

                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        className="text-muted-foreground hover:text-destructive size-9"
                                        onClick={() => setRows((prev) => prev.filter((_, i) => i !== index))}
                                        aria-label="حذف الخامة"
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>

                                    {effective > 0 && (
                                        <p className="text-muted-foreground text-[11px] sm:col-span-4">
                                            يُخصم من المخزون {effective} {product?.unitName ?? ''} {unitLabel}
                                            {Number(row.wastePct) > 0 ? ` (شاملةً % هالكاً)` : ''}
                                        </p>
                                    )}
                                </div>
                            );
                        })
                    )}

                    {products.length > 0 && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="w-full"
                            onClick={() => setRows((prev) => [...prev, { productId: '', qtyPerUnit: '', wastePct: '' }])}
                            disabled={chosenIds.size >= products.length && rows.every((r) => r.productId !== '')}
                        >
                            <Plus className="size-4" /> إضافة خامة
                        </Button>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button onClick={handleSave} disabled={processing || products.length === 0}>
                        {processing ? 'جاري الحفظ...' : 'حفظ'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
