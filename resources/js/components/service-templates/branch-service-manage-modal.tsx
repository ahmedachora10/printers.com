import {
    destroy as destroyBranchService,
    store as storeBranchService,
    update as updateBranchService,
} from '@/actions/App/Http/Controllers/BranchServiceController';
import BranchServiceEmployeesModal, {
    type BranchEmployee,
    type EmployeeCommission,
} from '@/components/branch-services/branch-service-employees-modal';
import NoteExamplesField from '@/components/branch-services/note-examples-field';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type BranchService, type BranchServiceFormData, type BranchServiceUpdateData } from '@/types/branch-service';
import { type ServiceTemplate } from '@/types/service-template';
import { router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2, Users, X } from 'lucide-react';
import { useState } from 'react';
import InputError from '../input-error';

interface BranchOption {
    id: number;
    name: string;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    template: ServiceTemplate | null;
    branches: BranchOption[];
    branchEmployees: Record<number, BranchEmployee[]>;
    employeeCommissions: Record<number, EmployeeCommission[]>;
}

type ActiveAction = { type: 'attach'; branchId: number } | { type: 'edit'; serviceId: number } | null;

export default function BranchServiceManageModal({ open, onOpenChange, template, branches, branchEmployees, employeeCommissions }: Props) {
    const [activeAction, setActiveAction] = useState<ActiveAction>(null);
    // The branch service whose per-employee rates are being edited.
    const [employeesService, setEmployeesService] = useState<BranchService | null>(null);

    const attachForm = useForm<BranchServiceFormData>({
        service_template_id: template?.id ?? 0,
        branch_id: 0,
        base_commission_pct: 0,
        max_discount_pct: 0,
        max_selling_price: null,
        min_selling_price: null,
        pricing_type: 'unit',
        price_per_sqm: 0,
        agent_commission_per_sqm: 0,
        note_examples: [],
        is_tahazir: false,
        has_materials: false,
        materials_cost: 0,
        is_active: true,
    });

    const editForm = useForm<BranchServiceUpdateData>({
        base_commission_pct: 0,
        max_discount_pct: 0,
        max_selling_price: null,
        min_selling_price: null,
        pricing_type: 'unit',
        price_per_sqm: 0,
        agent_commission_per_sqm: 0,
        note_examples: [],
        is_tahazir: false,
        has_materials: false,
        materials_cost: 0,
        is_active: true,
    });

    function handleOpenChange(nextOpen: boolean) {
        if (!nextOpen) {
            setActiveAction(null);
            attachForm.reset();
            editForm.reset();
        }
        onOpenChange(nextOpen);
    }

    function startAttach(branchId: number) {
        setActiveAction({ type: 'attach', branchId });
        attachForm.setData({
            service_template_id: template?.id ?? 0,
            branch_id: branchId,
            base_commission_pct: 0,
            max_discount_pct: 0,
            max_selling_price: null,
            min_selling_price: null,
            pricing_type: 'unit',
            price_per_sqm: 0,
            agent_commission_per_sqm: 0,
            note_examples: [],
            is_tahazir: false,
            has_materials: false,
            materials_cost: 0,
            is_active: true,
        });
    }

    function startEdit(service: BranchService) {
        setActiveAction({ type: 'edit', serviceId: service.id });
        editForm.setData({
            base_commission_pct: service.baseCommissionPct,
            max_discount_pct: service.maxDiscountPct,
            max_selling_price: service.maxSellingPrice ?? null,
            min_selling_price: service.minSellingPrice ?? null,
            pricing_type: service.pricingType ?? 'unit',
            price_per_sqm: service.pricePerSqm ?? 0,
            agent_commission_per_sqm: service.agentCommissionPerSqm ?? 0,
            note_examples: service.noteExamples ?? [],
            is_tahazir: service.isTahazir,
            has_materials: service.hasMaterials ?? false,
            materials_cost: service.materialsCost ?? 0,
            is_active: service.isActive,
        });
    }

    function cancelAction() {
        setActiveAction(null);
        attachForm.reset();
        editForm.reset();
    }

    function handleAttachSubmit(e: React.FormEvent) {
        e.preventDefault();
        attachForm.post(storeBranchService.url(), {
            preserveScroll: true,
            onSuccess: () => {
                setActiveAction(null);
                attachForm.reset();
            },
        });
    }

    function handleEditSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (activeAction?.type !== 'edit') return;
        editForm.put(updateBranchService.url({ id: activeAction.serviceId }), {
            preserveScroll: true,
            onSuccess: () => {
                setActiveAction(null);
                editForm.reset();
            },
        });
    }

    function handleDetach(service: BranchService) {
        router.delete(destroyBranchService.url(service), { preserveScroll: true });
    }

    const attachedServices = template?.branches ?? [];

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>إدارة فروع القالب: {template?.name}</DialogTitle>
                </DialogHeader>

                <div className="flex-1 space-y-2 overflow-y-auto py-2 pe-1">
                    {branches.length === 0 && <p className="text-muted-foreground py-8 text-center text-sm">لا توجد فروع نشطة.</p>}

                    {branches.map((branch) => {
                        const service = attachedServices.find((bs) => bs.branchId === branch.id);
                        const isAttached = !!service;
                        const isEditing = activeAction?.type === 'edit' && service && activeAction.serviceId === service.id;
                        const isAttaching = activeAction?.type === 'attach' && activeAction.branchId === branch.id;

                        return (
                            <div key={branch.id} className="bg-card rounded-lg border p-3 transition-colors">
                                {/* Row header */}
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-sm font-medium">{branch.name}</span>

                                    {!isEditing && !isAttaching && (
                                        <div className="flex items-center gap-1.5">
                                            {isAttached ? (
                                                <>
                                                    <span className="text-muted-foreground text-xs">
                                                        {service.baseCommissionPct}% عمولة • {service.maxDiscountPct}% خصم
                                                        {service.pricingType === 'sqm' && (
                                                            <span className="ms-1 rounded bg-sky-100 px-1 text-sky-700 dark:bg-sky-950 dark:text-sky-300">
                                                                م² {service.pricePerSqm} ر.س
                                                            </span>
                                                        )}
                                                        {service.isTahazir && (
                                                            <span className="ms-1 rounded bg-violet-100 px-1 text-violet-700 dark:bg-violet-950 dark:text-violet-300">
                                                                تحاضر
                                                            </span>
                                                        )}
                                                    </span>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="h-7 gap-1 px-2 text-xs"
                                                        onClick={() => setEmployeesService(service)}
                                                    >
                                                        <Users className="size-3" />
                                                        العمولات
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="h-7 gap-1 px-2 text-xs"
                                                        onClick={() => startEdit(service)}
                                                    >
                                                        <Pencil className="size-3" />
                                                        تعديل
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="text-destructive hover:text-destructive h-7 gap-1 px-2 text-xs"
                                                        onClick={() => handleDetach(service)}
                                                    >
                                                        <Trash2 className="size-3" />
                                                        فصل
                                                    </Button>
                                                </>
                                            ) : (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-7 gap-1 px-2 text-xs"
                                                    onClick={() => startAttach(branch.id)}
                                                >
                                                    <Plus className="size-3" />
                                                    ربط
                                                </Button>
                                            )}
                                        </div>
                                    )}

                                    {(isEditing || isAttaching) && (
                                        <Button size="sm" variant="ghost" className="h-7 w-7 p-0" onClick={cancelAction}>
                                            <X className="size-3.5" />
                                        </Button>
                                    )}
                                </div>

                                {/* Inline edit form */}
                                {isEditing && (
                                    <form onSubmit={handleEditSubmit} className="mt-3 space-y-3 border-t pt-3">
                                        <BranchServiceFields
                                            data={editForm.data}
                                            errors={editForm.errors}
                                            setData={editForm.setData as (key: string, value: number | boolean | string | string[] | null) => void}
                                        />
                                        <div className="flex justify-end gap-2">
                                            <Button type="button" size="sm" variant="outline" onClick={cancelAction} disabled={editForm.processing}>
                                                إلغاء
                                            </Button>
                                            <Button type="submit" size="sm" disabled={editForm.processing}>
                                                {editForm.processing ? 'جاري الحفظ...' : 'حفظ'}
                                            </Button>
                                        </div>
                                    </form>
                                )}

                                {/* Inline attach form */}
                                {isAttaching && (
                                    <form onSubmit={handleAttachSubmit} className="mt-3 space-y-3 border-t pt-3">
                                        <BranchServiceFields
                                            data={attachForm.data}
                                            errors={attachForm.errors}
                                            setData={attachForm.setData as (key: string, value: number | boolean | string | string[] | null) => void}
                                        />
                                        <div className="flex justify-end gap-2">
                                            <Button type="button" size="sm" variant="outline" onClick={cancelAction} disabled={attachForm.processing}>
                                                إلغاء
                                            </Button>
                                            <Button type="submit" size="sm" disabled={attachForm.processing}>
                                                {attachForm.processing ? 'جاري الربط...' : 'ربط'}
                                            </Button>
                                        </div>
                                    </form>
                                )}
                            </div>
                        );
                    })}
                </div>
            </DialogContent>

            {/* Per-employee commission rates for a branch service */}
            <BranchServiceEmployeesModal
                key={employeesService?.id ?? 'employees'}
                open={employeesService !== null}
                onOpenChange={(nextOpen) => !nextOpen && setEmployeesService(null)}
                branchServiceId={employeesService?.id ?? null}
                serviceName={template?.name ?? ''}
                employees={employeesService ? (branchEmployees[employeesService.branchId] ?? []) : []}
                current={employeesService ? (employeeCommissions[employeesService.id] ?? []) : []}
            />
        </Dialog>
    );
}

interface FieldsProps {
    data: {
        base_commission_pct: number;
        max_discount_pct: number;
        max_selling_price: number | null;
        min_selling_price: number | null;
        pricing_type: 'unit' | 'sqm';
        price_per_sqm: number;
        agent_commission_per_sqm: number;
        note_examples: string[];
        is_tahazir: boolean;
        has_materials: boolean;
        materials_cost: number;
        is_active: boolean;
    };
    errors: Partial<Record<string, string>>;
    setData: (key: string, value: number | boolean | string | string[] | null) => void;
}

function BranchServiceFields({ data, errors, setData }: FieldsProps) {
    return (
        <>
            <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                    <Label className="text-xs">
                        نسبة العمولة (%) <span className="text-destructive">*</span>
                    </Label>
                    <Input
                        type="number"
                        step="0.01"
                        min="0"
                        max="100"
                        className="h-8 text-sm"
                        value={data.base_commission_pct}
                        onChange={(e) => setData('base_commission_pct', parseFloat(e.target.value) || 0)}
                        dir="ltr"
                    />
                    <InputError message={errors.base_commission_pct} />
                </div>

                <div className="space-y-1">
                    <Label className="text-xs">
                        الخصم الأقصى (%) <span className="text-destructive">*</span>
                    </Label>
                    <Input
                        type="number"
                        step="0.01"
                        min="0"
                        max="100"
                        className="h-8 text-sm"
                        value={data.max_discount_pct}
                        onChange={(e) => setData('max_discount_pct', parseFloat(e.target.value) || 0)}
                        dir="ltr"
                    />
                    <InputError message={errors.max_discount_pct} />
                </div>
            </div>

            <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                    <Label className="text-xs">نوع التسعير</Label>
                    <select
                        className="border-input bg-background h-8 w-full rounded-md border px-2 text-sm"
                        value={data.pricing_type}
                        onChange={(e) => setData('pricing_type', e.target.value)}
                    >
                        <option value="unit">بالوحدة</option>
                        <option value="sqm">بالمتر المربع</option>
                    </select>
                    <InputError message={errors.pricing_type} />
                </div>

                {data.pricing_type === 'sqm' && (
                    <div className="space-y-1">
                        <Label className="text-xs">
                            سعر المتر المربع (ر.س) <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            className="h-8 text-sm"
                            value={data.price_per_sqm}
                            onChange={(e) => setData('price_per_sqm', parseFloat(e.target.value) || 0)}
                            dir="ltr"
                        />
                        <InputError message={errors.price_per_sqm} />
                    </div>
                )}
            </div>

            {data.pricing_type === 'sqm' && (
                <div className="grid grid-cols-2 gap-3">
                    <div className="space-y-1">
                        <Label className="text-xs">عمولة المندوب للمتر (ر.س)</Label>
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            className="h-8 text-sm"
                            value={data.agent_commission_per_sqm}
                            onChange={(e) => setData('agent_commission_per_sqm', parseFloat(e.target.value) || 0)}
                            dir="ltr"
                        />
                        <InputError message={errors.agent_commission_per_sqm} />
                    </div>
                </div>
            )}

            {/* سقف سعر البيع — يمنع الموظف من تجاوزه، وفارغه يترك السعر مفتوحاً */}
            <div className="space-y-1">
                <Label className="text-xs">
                    {data.pricing_type === 'sqm' ? 'أعلى سعر للمتر المربع (ر.س)' : 'أعلى سعر للبيع (ر.س)'} — شامل الضريبة
                </Label>
                <Input
                    type="number"
                    step="0.01"
                    min="0"
                    className="h-8 text-sm"
                    placeholder="فارغة = السعر مفتوح"
                    value={data.max_selling_price ?? ''}
                    onChange={(e) => setData('max_selling_price', e.target.value === '' ? null : Math.max(0, parseFloat(e.target.value) || 0))}
                    dir="ltr"
                />
                <InputError message={errors.max_selling_price} />
            </div>

            {/* أرضية سعر البيع (تاسك 64) — مرآة السقف أعلاه */}
            <div className="space-y-1">
                <Label className="text-xs">{data.pricing_type === 'sqm' ? 'أقل سعر للمتر المربع (ر.س)' : 'أقل سعر للبيع (ر.س)'} — شامل الضريبة</Label>
                <Input
                    type="number"
                    step="0.01"
                    min="0"
                    className="h-8 text-sm"
                    placeholder="فارغة = لا حدّ أدنى"
                    value={data.min_selling_price ?? ''}
                    onChange={(e) => setData('min_selling_price', e.target.value === '' ? null : Math.max(0, parseFloat(e.target.value) || 0))}
                    dir="ltr"
                />
                <InputError message={errors.min_selling_price} />
            </div>

            <NoteExamplesField
                value={data.note_examples}
                onChange={(next) => setData('note_examples', next)}
                error={errors.note_examples}
                compact
                idPrefix="bs-manage"
            />

            {/* الخامات: تكلفة افتراضية تُعبّئ خانة السطر في نقطة البيع وتُخصم
                من أساس عمولة الموظف وحده — لا تظهر للعميل ولا تدخل الإجمالي. */}
            {data.has_materials && (
                <div className="space-y-1">
                    {/* وحدة المبلغ تتبع نوع التسعير (تاسك 63). */}
                    <Label className="text-xs">
                        {data.pricing_type === 'sqm' ? 'تكلفة الخامات للمتر المربع (ر.س)' : 'تكلفة الخامات للوحدة (ر.س)'} — بلا ضريبة
                    </Label>
                    <Input
                        type="number"
                        step="0.01"
                        min="0"
                        className="h-8 text-sm"
                        value={data.materials_cost}
                        onChange={(e) => setData('materials_cost', parseFloat(e.target.value) || 0)}
                        dir="ltr"
                    />
                    <InputError message={errors.materials_cost} />
                </div>
            )}

            <div className="flex items-center gap-6">
                <div className="flex items-center gap-2">
                    <Checkbox id="bs-tahazir" checked={data.is_tahazir} onCheckedChange={(checked) => setData('is_tahazir', checked === true)} />
                    <Label htmlFor="bs-tahazir" className="cursor-pointer text-xs">
                        تحاضر
                    </Label>
                </div>

                <div className="flex items-center gap-2">
                    <Checkbox
                        id="bs-materials"
                        checked={data.has_materials}
                        onCheckedChange={(checked) => setData('has_materials', checked === true)}
                    />
                    <Label htmlFor="bs-materials" className="cursor-pointer text-xs">
                        لها خامات
                    </Label>
                </div>

                <div className="flex items-center gap-2">
                    <Checkbox id="bs-active" checked={data.is_active} onCheckedChange={(checked) => setData('is_active', checked === true)} />
                    <Label htmlFor="bs-active" className="cursor-pointer text-xs">
                        نشط
                    </Label>
                </div>
            </div>
        </>
    );
}
