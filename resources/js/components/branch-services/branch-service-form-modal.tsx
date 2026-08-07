import { store, update } from '@/actions/App/Http/Controllers/BranchServiceController';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { type BranchService, type BranchServiceFormData } from '@/types/branch-service';
import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import InputError from '../input-error';
import NoteExamplesField from './note-examples-field';

interface ServiceTemplateOption {
    id: number;
    name: string;
}

interface BranchOption {
    id: number;
    name: string;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    userBranch: BranchOption;
    serviceTemplates: ServiceTemplateOption[];
    branchService?: BranchService;
}

export default function BranchServiceFormModal({ open, onOpenChange, userBranch, serviceTemplates, branchService }: Props) {
    const isEdit = !!branchService;

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm<BranchServiceFormData>({
        service_template_id: branchService?.serviceTemplateId ?? 0,
        branch_id: userBranch.id,
        base_commission_pct: branchService?.baseCommissionPct ?? 0,
        max_discount_pct: branchService?.maxDiscountPct ?? 0,
        pricing_type: branchService?.pricingType ?? 'unit',
        price_per_sqm: branchService?.pricePerSqm ?? 0,
        agent_commission_per_sqm: branchService?.agentCommissionPerSqm ?? 0,
        note_examples: branchService?.noteExamples ?? [],
        is_tahazir: branchService?.isTahazir ?? false,
        has_materials: branchService?.hasMaterials ?? false,
        materials_cost: branchService?.materialsCost ?? 0,
        is_active: branchService?.isActive ?? true,
    });

    useEffect(() => {
        if (branchService) {
            setData({
                service_template_id: branchService.serviceTemplateId ?? 0,
                branch_id: userBranch.id,
                base_commission_pct: branchService.baseCommissionPct ?? 0,
                max_discount_pct: branchService.maxDiscountPct ?? 0,
                pricing_type: branchService.pricingType ?? 'unit',
                price_per_sqm: branchService.pricePerSqm ?? 0,
                agent_commission_per_sqm: branchService.agentCommissionPerSqm ?? 0,
                note_examples: branchService.noteExamples ?? [],
                is_tahazir: branchService.isTahazir ?? false,
                has_materials: branchService.hasMaterials ?? false,
                materials_cost: branchService.materialsCost ?? 0,
                is_active: branchService.isActive ?? true,
            });
        } else {
            reset();
        }
    }, [branchService, open]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                clearErrors();
                onOpenChange(false);
                reset();
            },
        };

        if (isEdit) {
            put(update.url(branchService), options);
        } else {
            post(store.url(), options);
        }
    }

    function handleOpenChange(nextOpen: boolean) {
        if (!nextOpen) {
            reset();
            clearErrors();
        }
        onOpenChange(nextOpen);
    }

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'تعديل خدمة الفرع' : 'ربط خدمة بالفرع'}</DialogTitle>
                </DialogHeader>

                <form id="bs-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    {/* Branch — read-only */}
                    <div className="space-y-1">
                        <Label htmlFor="bs-branch">
                            الفرع <span className="text-destructive">*</span>
                        </Label>
                        <Input id="bs-branch" value={userBranch.name} disabled className="bg-muted/50 text-muted-foreground" />
                    </div>

                    {/* Service template */}
                    <div className="space-y-1">
                        <Label htmlFor="bs-template">
                            الخدمة <span className="text-destructive">*</span>
                        </Label>
                        {isEdit ? (
                            <Input
                                id="bs-template"
                                value={branchService.serviceTemplateName ?? ''}
                                disabled
                                className="bg-muted/50 text-muted-foreground"
                            />
                        ) : (
                            <Select
                                value={data.service_template_id ? String(data.service_template_id) : ''}
                                onValueChange={(val) => setData('service_template_id', Number(val))}
                            >
                                <SelectTrigger id="bs-template">
                                    <SelectValue placeholder="اختر الخدمة" />
                                </SelectTrigger>
                                <SelectContent>
                                    {serviceTemplates.map((t) => (
                                        <SelectItem key={t.id} value={String(t.id)}>
                                            {t.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        <InputError message={errors.service_template_id || errors.branch_id} />
                    </div>

                    {/* Commission % */}
                    <div className="space-y-1">
                        <Label htmlFor="bs-commission">
                            نسبة العمولة (%) <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id="bs-commission"
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            dir="ltr"
                            value={data.base_commission_pct}
                            onChange={(e) => setData('base_commission_pct', parseFloat(e.target.value) || 0)}
                        />
                        <InputError message={errors.base_commission_pct} />
                    </div>

                    {/* Max discount % */}
                    <div className="space-y-1">
                        <Label htmlFor="bs-discount">
                            أقصى نسبة خصم (%) <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id="bs-discount"
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            dir="ltr"
                            value={data.max_discount_pct}
                            onChange={(e) => setData('max_discount_pct', parseFloat(e.target.value) || 0)}
                        />
                        <InputError message={errors.max_discount_pct} />
                    </div>

                    {/* Pricing type: per-unit or per-square-meter */}
                    <div className="space-y-1">
                        <Label htmlFor="bs-pricing-type">نوع التسعير</Label>
                        <Select value={data.pricing_type} onValueChange={(val) => setData('pricing_type', val as 'unit' | 'sqm')}>
                            <SelectTrigger id="bs-pricing-type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="unit">بالوحدة</SelectItem>
                                <SelectItem value="sqm">بالمتر المربع</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={errors.pricing_type} />
                    </div>

                    {data.pricing_type === 'sqm' && (
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label htmlFor="bs-price-sqm">
                                    سعر المتر المربع (ر.س) <span className="text-destructive">*</span>
                                </Label>
                                <Input
                                    id="bs-price-sqm"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    dir="ltr"
                                    value={data.price_per_sqm}
                                    onChange={(e) => setData('price_per_sqm', parseFloat(e.target.value) || 0)}
                                />
                                <InputError message={errors.price_per_sqm} />
                            </div>
                            <div className="space-y-1">
                                <Label htmlFor="bs-agent-sqm">عمولة المندوب للمتر (ر.س)</Label>
                                <Input
                                    id="bs-agent-sqm"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    dir="ltr"
                                    value={data.agent_commission_per_sqm}
                                    onChange={(e) => setData('agent_commission_per_sqm', parseFloat(e.target.value) || 0)}
                                />
                                <InputError message={errors.agent_commission_per_sqm} />
                            </div>
                        </div>
                    )}

                    {/* Ready-made detail phrases — become the POS placeholder */}
                    <NoteExamplesField value={data.note_examples} onChange={(next) => setData('note_examples', next)} error={errors.note_examples} />

                    {/* Tahazir + Materials + Active switches */}
                    <div className="flex flex-col gap-3 pt-1">
                        <div className="flex items-center justify-between">
                            <Label htmlFor="bs-tahazir" className="cursor-pointer">
                                خدمة تحضير
                            </Label>
                            <Switch id="bs-tahazir" checked={data.is_tahazir} onCheckedChange={(checked) => setData('is_tahazir', checked)} />
                        </div>

                        {/* الخامات: التكلفة الافتراضية تُعبّئ خانة السطر في نقطة البيع
                            وتبقى قابلة للتعديل هناك. تُخصم من عمولة الموظف وحدها. */}
                        <div className="flex items-center justify-between">
                            <Label htmlFor="bs-materials" className="cursor-pointer">
                                لها خامات
                            </Label>
                            <Switch
                                id="bs-materials"
                                checked={data.has_materials}
                                onCheckedChange={(checked) => setData('has_materials', checked)}
                            />
                        </div>

                        {data.has_materials && (
                            <div className="grid gap-2">
                                <Label htmlFor="bs-materials-cost">تكلفة الخامات للوحدة (ر.س)</Label>
                                <Input
                                    id="bs-materials-cost"
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={data.materials_cost}
                                    onChange={(e) => setData('materials_cost', parseFloat(e.target.value) || 0)}
                                />
                                <InputError message={errors.materials_cost} />
                                <p className="text-muted-foreground text-xs">
                                    قيمة مقترحة فقط — الموظف يعدّلها لكل فاتورة. لا تظهر للعميل ولا تدخل في الإجمالي.
                                </p>
                            </div>
                        )}

                        <div className="flex items-center justify-between">
                            <Label htmlFor="bs-active" className="cursor-pointer">
                                نشطة
                            </Label>
                            <Switch id="bs-active" checked={data.is_active} onCheckedChange={(checked) => setData('is_active', checked)} />
                        </div>
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="bs-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : 'حفظ'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
