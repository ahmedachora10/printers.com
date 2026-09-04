import { store, update } from '@/actions/App/Http/Controllers/BranchServiceController';
import { store as storeServiceTemplate } from '@/actions/App/Http/Controllers/ServiceTemplateController';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { meterLabel } from '@/lib/service-pricing';
import { type BranchService, type BranchServiceFormData, type ServicePricingType } from '@/types/branch-service';
import { router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import InputError from '../input-error';
import NoteExamplesField from './note-examples-field';

interface ServiceTemplateOption {
    id: number;
    name: string;
    /** خدمة أنشأها هذا الفرع لنفسه، لا خدمة عامة (تاسك 45) */
    isOwn?: boolean;
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
        max_selling_price: branchService?.maxSellingPrice ?? null,
        min_selling_price: branchService?.minSellingPrice ?? null,
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
                max_selling_price: branchService.maxSellingPrice ?? null,
                min_selling_price: branchService.minSellingPrice ?? null,
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

    // ── خدمة جديدة يضيفها مدير الفرع بنفسه (تاسك 45) ──────────────────
    // الخدمة تُنشأ مملوكةً لفرعه على الخادم، ثم تُنتقى تلقائياً حين تعود ضمن
    // القائمة المحدَّثة — فلا يخرج من المودال ليعود إليه.
    const [addingTemplate, setAddingTemplate] = useState(false);
    const [templateName, setTemplateName] = useState('');
    const [templateError, setTemplateError] = useState<string | undefined>();
    const [creatingTemplate, setCreatingTemplate] = useState(false);
    const awaitingTemplate = useRef<string | null>(null);

    useEffect(() => {
        if (!awaitingTemplate.current) return;

        const created = serviceTemplates.find((t) => t.name === awaitingTemplate.current);
        if (!created) return;

        setData('service_template_id', created.id);
        awaitingTemplate.current = null;
        setAddingTemplate(false);
        setTemplateName('');
    }, [serviceTemplates]);

    function createTemplate() {
        const name = templateName.trim();

        if (name === '') {
            setTemplateError('أدخل اسم الخدمة.');
            return;
        }

        setCreatingTemplate(true);
        setTemplateError(undefined);

        router.post(
            storeServiceTemplate.url(),
            { name, is_active: true },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    awaitingTemplate.current = name;
                },
                onError: (errs) => setTemplateError(errs.name ?? 'تعذّر إنشاء الخدمة.'),
                onFinish: () => setCreatingTemplate(false),
            },
        );
    }

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
                            <>
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
                                                {t.isOwn && <span className="text-muted-foreground text-xs"> — خاصة بالفرع</span>}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>

                                {addingTemplate ? (
                                    <div className="bg-muted/40 mt-2 space-y-2 rounded-md border p-3">
                                        <Label htmlFor="bs-new-template">اسم الخدمة الجديدة</Label>
                                        <div className="flex gap-2">
                                            <Input
                                                id="bs-new-template"
                                                value={templateName}
                                                onChange={(e) => setTemplateName(e.target.value)}
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter') {
                                                        e.preventDefault();
                                                        createTemplate();
                                                    }
                                                }}
                                                placeholder="مثال: طباعة استيكرات"
                                                autoFocus
                                            />
                                            <Button type="button" onClick={createTemplate} disabled={creatingTemplate}>
                                                {creatingTemplate ? '...' : 'إضافة'}
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                onClick={() => {
                                                    setAddingTemplate(false);
                                                    setTemplateError(undefined);
                                                }}
                                                disabled={creatingTemplate}
                                            >
                                                إلغاء
                                            </Button>
                                        </div>
                                        <InputError message={templateError} />
                                        <p className="text-muted-foreground text-xs">تُضاف الخدمة لفرعك وحده ولا تظهر لبقية الفروع.</p>
                                    </div>
                                ) : (
                                    <Button type="button" variant="link" className="h-auto p-0 text-xs" onClick={() => setAddingTemplate(true)}>
                                        <Plus className="size-3" /> لم تجد الخدمة؟ أضف خدمة جديدة
                                    </Button>
                                )}
                            </>
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
                        <Select value={data.pricing_type} onValueChange={(val) => setData('pricing_type', val as ServicePricingType)}>
                            <SelectTrigger id="bs-pricing-type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="unit">بالوحدة</SelectItem>
                                <SelectItem value="sqm">بالمتر المربع</SelectItem>
                                {/* تاسك 80: بُعدٌ واحد — نقطة البيع تطلب الطول وحده. */}
                                <SelectItem value="linear">بالمتر الطولي</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError message={errors.pricing_type} />
                    </div>

                    {data.pricing_type !== 'unit' && (
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1">
                                <Label htmlFor="bs-price-sqm">
                                    {data.pricing_type === 'linear' ? 'سعر المتر الطولي (ر.س)' : 'سعر المتر المربع (ر.س)'}{' '}
                                    <span className="text-destructive">*</span>
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

                    {/* سقف سعر البيع — يُلزِم الموظف وحده، وفارغه يترك السعر مفتوحاً */}
                    <div className="space-y-1">
                        <Label htmlFor="bs-max-price">
                            {data.pricing_type === 'unit' ? 'أعلى سعر للبيع (ر.س)' : `أعلى سعر ${meterLabel(data.pricing_type)} (ر.س)`}
                            <span className="text-muted-foreground me-1 text-xs font-normal">شامل الضريبة</span>
                        </Label>
                        <Input
                            id="bs-max-price"
                            type="number"
                            step="0.01"
                            min="0"
                            dir="ltr"
                            placeholder="اتركها فارغة ليكون السعر مفتوحاً"
                            value={data.max_selling_price ?? ''}
                            onChange={(e) =>
                                setData('max_selling_price', e.target.value === '' ? null : Math.max(0, parseFloat(e.target.value) || 0))
                            }
                        />
                        <p className="text-muted-foreground text-xs">
                            {data.pricing_type !== 'unit'
                                ? 'لا يستطيع الموظف بيع المتر بأعلى من هذا السعر. فارغة = السعر مفتوح له.'
                                : 'لا يستطيع الموظف البيع بأعلى من هذا السعر. فارغة = السعر مفتوح له.'}
                        </p>
                        <InputError message={errors.max_selling_price} />
                    </div>

                    {/* أرضية السعر (تاسك 64) — مرآة السقف أعلاه. والأرضية الفعلية في
                        نقطة البيع أعلى هذا الرقم وتكلفةِ خامات السطر (تاسك 65). */}
                    <div className="space-y-1">
                        <Label htmlFor="bs-min-price">
                            {data.pricing_type === 'unit' ? 'أقل سعر للبيع (ر.س)' : `أقل سعر ${meterLabel(data.pricing_type)} (ر.س)`}
                            <span className="text-muted-foreground me-1 text-xs font-normal">شامل الضريبة</span>
                        </Label>
                        <Input
                            id="bs-min-price"
                            type="number"
                            step="0.01"
                            min="0"
                            dir="ltr"
                            placeholder="اتركها فارغة ليكون السعر مفتوحاً"
                            value={data.min_selling_price ?? ''}
                            onChange={(e) =>
                                setData('min_selling_price', e.target.value === '' ? null : Math.max(0, parseFloat(e.target.value) || 0))
                            }
                        />
                        <p className="text-muted-foreground text-xs">
                            {data.pricing_type !== 'unit'
                                ? 'لا يستطيع الموظف بيع المتر بأقل من هذا السعر — يُقارَن بالسعر المكتوب في نقطة البيع كما هو (شاملاً الضريبة) بعد الخصم. فارغة = لا حدّ أدنى.'
                                : 'لا يستطيع الموظف البيع بأقل من هذا السعر — يُقارَن بالسعر المكتوب في نقطة البيع كما هو (شاملاً الضريبة) بعد الخصم. فارغة = لا حدّ أدنى.'}
                        </p>
                        <InputError message={errors.min_selling_price} />
                    </div>

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
                            <Switch id="bs-materials" checked={data.has_materials} onCheckedChange={(checked) => setData('has_materials', checked)} />
                        </div>

                        {data.has_materials && (
                            <div className="grid gap-2">
                                {/* وحدة المبلغ تتبع نوع التسعير (تاسك 63): خدمةٌ بالمتر
                                    المربع تكلفة خامتها للمتر وتُضرب في مساحة السطر. */}
                                <Label htmlFor="bs-materials-cost">
                                    {data.pricing_type === 'unit'
                                        ? 'تكلفة الخامات للوحدة (ر.س)'
                                        : `تكلفة الخامات ${meterLabel(data.pricing_type)} (ر.س)`}
                                    <span className="text-muted-foreground me-1 text-xs font-normal">بلا ضريبة</span>
                                </Label>
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
                                    لا تظهر للعميل ولا تدخل في الإجمالي. وهي تكلفة صافية: تُخصم من أساس عمولة الموظف صافيةً، وتُرفع بالضريبة وحدها
                                    عند منع البيع بأقل منها (تكلفة 20 = لا تُباع بأقل من 23.00 شاملة).
                                    {data.pricing_type === 'sqm' && ' تُضرب في مساحة السطر: خامة 10 ر.س على مقاس 100×70 سم = 7 ر.س.'}
                                    {data.pricing_type === 'linear' && ' تُضرب في طول السطر: خامة 10 ر.س على طول 200 سم = 20 ر.س.'}
                                </p>
                                {/* تاسك 77: الصفر معناه «تُحدَّد وقت البيع» لا «بلا خامات». */}
                                <p className="text-muted-foreground text-xs">اتركها صفراً ليُدخلها الموظف مع كل فاتورة.</p>
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
