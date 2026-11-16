import {
    bulkDestroy,
    bulkUpsert,
    destroy as destroyBranchService,
    store as storeBranchService,
    update as updateBranchService,
} from '@/actions/App/Http/Controllers/BranchServiceController';
import BranchServiceAgentsModal, { type AgentCommission, type BranchAgent } from '@/components/branch-services/branch-service-agents-modal';
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
import { isMeasured, meterLabel } from '@/lib/service-pricing';
import { type BranchService, type BranchServiceFormData, type BranchServiceUpdateData, type ServicePricingType } from '@/types/branch-service';
import { type ServiceTemplate } from '@/types/service-template';
import { router, useForm } from '@inertiajs/react';
import { Copy, Layers, Pencil, Plus, Trash2, UserRoundCheck, Users, X } from 'lucide-react';
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
    branchAgents: Record<number, BranchAgent[]>;
    agentCommissions: Record<number, AgentCommission[]>;
}

type ActiveAction = { type: 'attach'; branchId: number } | { type: 'edit'; serviceId: number } | null;

/** مجموعات الحقول في التطبيق الجماعي — مرآة BulkUpsertBranchServicesAction::GROUPS. */
const FIELD_GROUPS = {
    base_commission_pct: { label: 'نسبة العمولة', columns: ['base_commission_pct'] },
    max_discount_pct: { label: 'الخصم الأقصى', columns: ['max_discount_pct'] },
    pricing: { label: 'التسعير', columns: ['pricing_type', 'price_per_sqm', 'agent_commission_per_sqm'] },
    price_bounds: { label: 'حدود سعر البيع', columns: ['max_selling_price', 'min_selling_price'] },
    note_examples: { label: 'أمثلة التفاصيل', columns: ['note_examples'] },
    materials: { label: 'الخامات', columns: ['has_materials', 'materials_cost'] },
    is_active: { label: 'الحالة', columns: ['is_active'] },
} as const;

type FieldGroup = keyof typeof FIELD_GROUPS;

export default function BranchServiceManageModal({
    open,
    onOpenChange,
    template,
    branches,
    branchEmployees,
    employeeCommissions,
    branchAgents,
    agentCommissions,
}: Props) {
    const [activeAction, setActiveAction] = useState<ActiveAction>(null);
    // The branch service whose per-employee rates are being edited.
    const [employeesService, setEmployeesService] = useState<BranchService | null>(null);
    // تاسك 153: والخدمة التي تُعدَّل عمولات مناديبها.
    const [agentsService, setAgentsService] = useState<BranchService | null>(null);
    // تاسك 79: الرابط الذي نُسخت منه شروط الربط الجاري — 0 يعني «بلا نسخ».
    const [copySourceId, setCopySourceId] = useState(0);

    // التحديد الجماعي: معرّفات الفروع، والحقول المختارة للتطبيق عليها.
    const [selected, setSelected] = useState<number[]>([]);
    const [bulkOpen, setBulkOpen] = useState(false);
    const [groups, setGroups] = useState<FieldGroup[]>([]);

    const attachedServices = template?.branches ?? [];
    const serviceOf = (branchId: number) => attachedServices.find((bs) => bs.branchId === branchId);
    const selectedLinked = selected.filter((id) => serviceOf(id));
    const selectedNew = selected.length - selectedLinked.length;

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
        has_materials: false,
        materials_cost: 0,
        is_active: true,
    });

    const bulkForm = useForm<BranchServiceUpdateData>({ ...editForm.data });

    function handleOpenChange(nextOpen: boolean) {
        if (!nextOpen) {
            setActiveAction(null);
            attachForm.reset();
            editForm.reset();
            closeBulk();
            setSelected([]);
        }
        onOpenChange(nextOpen);
    }

    function toggleSelected(branchId: number, checked: boolean) {
        setSelected((current) => (checked ? [...current, branchId] : current.filter((id) => id !== branchId)));
    }

    function openBulk() {
        const source = attachedServices[attachedServices.length - 1];
        cancelAction();
        setCopySourceId(source?.id ?? 0);
        setGroups([]);
        bulkForm.clearErrors();
        bulkForm.setData({ ...termsFrom(source) });
        setBulkOpen(true);
    }

    function closeBulk() {
        setBulkOpen(false);
        setGroups([]);
        setCopySourceId(0);
        bulkForm.clearErrors();
    }

    /** مصدر القيم تغيّر: الحقول غير المختارة تُعاد منه، والمختارة تبقى على ما كتبه المستخدم. */
    function applyBulkSource(serviceId: number) {
        setCopySourceId(serviceId);
        const terms = termsFrom(attachedServices.find((bs) => bs.id === serviceId));
        bulkForm.setData((current) => {
            const next: BranchServiceUpdateData = { ...current, ...terms, note_examples: [...terms.note_examples] };
            groups.forEach((g) => FIELD_GROUPS[g].columns.forEach((c: string) => (next[c] = current[c])));
            return next;
        });
    }

    /** إخفاء حقل يعيده إلى قيمة المصدر — فلا يتسرّب تعديلٌ مخفيّ إلى الفروع الجديدة. */
    function toggleGroup(group: FieldGroup) {
        if (!groups.includes(group)) {
            setGroups([...groups, group]);
            return;
        }
        setGroups(groups.filter((g) => g !== group));
        const terms = termsFrom(attachedServices.find((bs) => bs.id === copySourceId));
        bulkForm.setData((current) => {
            const next: BranchServiceUpdateData = { ...current };
            const source: BranchServiceUpdateData = { ...terms, note_examples: [...terms.note_examples] };
            FIELD_GROUPS[group].columns.forEach((c: string) => (next[c] = source[c]));
            return next;
        });
    }

    function handleBulkSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (!template) return;
        bulkForm.transform((data) => ({ ...data, branch_ids: selected, fields: groups }));
        bulkForm.post(bulkUpsert.url(template.id), {
            preserveScroll: true,
            onSuccess: () => {
                closeBulk();
                setSelected([]);
            },
        });
    }

    function handleBulkDetach() {
        if (!template || selectedLinked.length === 0) return;
        if (!window.confirm(`فصل الخدمة عن ${selectedLinked.length} فرع؟ تُحذف معها عمولات الموظفين والمناديب لهذه الفروع.`)) return;
        router.delete(bulkDestroy.url(template.id), {
            data: { ids: selectedLinked.map((id) => serviceOf(id)!.id) },
            preserveScroll: true,
            onSuccess: () => setSelected((current) => current.filter((id) => !selectedLinked.includes(id))),
        });
    }

    /**
     * تاسك 79: شروط رابطٍ قائم كقيمٍ أوّلية لربطٍ جديد — ثلاث عشرة خانة كان
     * المستخدم يعيد كتابتها لكل فرع. عمولات الموظفين تُستثنى عمداً: هي مربوطة
     * بموظفي فرعٍ بعينه فلا معنى لنقلها، وتُدار من زرّ «العمولات» وحده.
     * و`is_active` تبدأ true دائماً مهما كانت حال المصدر.
     */
    function termsFrom(source: BranchService | undefined) {
        return {
            base_commission_pct: source?.baseCommissionPct ?? 0,
            max_discount_pct: source?.maxDiscountPct ?? 0,
            max_selling_price: source?.maxSellingPrice ?? null,
            min_selling_price: source?.minSellingPrice ?? null,
            pricing_type: source?.pricingType ?? 'unit',
            price_per_sqm: source?.pricePerSqm ?? 0,
            agent_commission_per_sqm: source?.agentCommissionPerSqm ?? 0,
            note_examples: source?.noteExamples ?? [],
            has_materials: source?.hasMaterials ?? false,
            materials_cost: source?.materialsCost ?? 0,
            is_active: true,
        } as const;
    }

    function startAttach(branchId: number) {
        // آخر فرعٍ رُبط هو المصدر الافتراضي — وهو المرجَّح أن يكون أحدث الشروط.
        const source = attachedServices[attachedServices.length - 1];

        closeBulk();
        setActiveAction({ type: 'attach', branchId });
        setCopySourceId(source?.id ?? 0);
        attachForm.setData({
            service_template_id: template?.id ?? 0,
            branch_id: branchId,
            ...termsFrom(source),
        });
    }

    /** تبديل الفرع المنسوخ منه، فتُعاد تعبئة الخانات من شروطه. */
    function applyCopySource(serviceId: number) {
        setCopySourceId(serviceId);
        attachForm.setData((current) => ({
            ...current,
            ...termsFrom(attachedServices.find((bs) => bs.id === serviceId)),
        }));
    }

    function startEdit(service: BranchService) {
        closeBulk();
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
            has_materials: service.hasMaterials ?? false,
            materials_cost: service.materialsCost ?? 0,
            is_active: service.isActive,
        });
    }

    function cancelAction() {
        setActiveAction(null);
        setCopySourceId(0);
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

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>إدارة فروع القالب: {template?.name}</DialogTitle>
                </DialogHeader>

                {/* شريط التحديد الجماعي */}
                {branches.length > 0 && (
                    <div className="bg-muted/40 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border px-3 py-2 text-xs">
                        <span className="text-muted-foreground">تحديد:</span>
                        {(
                            [
                                ['الكل', () => setSelected(branches.map((b) => b.id))],
                                ['المربوطة', () => setSelected(branches.filter((b) => serviceOf(b.id)).map((b) => b.id))],
                                ['غير المربوطة', () => setSelected(branches.filter((b) => !serviceOf(b.id)).map((b) => b.id))],
                                ['مسح', () => setSelected([])],
                            ] as const
                        ).map(([label, onClick]) => (
                            <button key={label} type="button" className="text-primary hover:underline" onClick={onClick}>
                                {label}
                            </button>
                        ))}

                        {selected.length > 0 && (
                            <div className="ms-auto flex items-center gap-1.5">
                                <span className="text-muted-foreground">{selected.length} محدد</span>
                                <Button size="sm" className="h-7 gap-1 px-2 text-xs" onClick={openBulk} disabled={bulkOpen}>
                                    <Layers className="size-3" />
                                    تطبيق على المحدد
                                </Button>
                                {selectedLinked.length > 0 && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="text-destructive hover:text-destructive h-7 gap-1 px-2 text-xs"
                                        onClick={handleBulkDetach}
                                    >
                                        <Trash2 className="size-3" />
                                        فصل ({selectedLinked.length})
                                    </Button>
                                )}
                            </div>
                        )}
                    </div>
                )}

                <div className="flex-1 space-y-2 overflow-y-auto py-2 pe-1">
                    {branches.length === 0 && <p className="text-muted-foreground py-8 text-center text-sm">لا توجد فروع نشطة.</p>}

                    {/* لوحة التطبيق الجماعي: الحقول المختارة وحدها تُكتب على الفروع المربوطة،
                        والفروع الجديدة تأخذ بقية الحقول من «نسخ القيم من». */}
                    {bulkOpen && (
                        <form onSubmit={handleBulkSubmit} className="border-primary/40 bg-card space-y-3 rounded-lg border-2 p-3">
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-sm font-medium">
                                    تطبيق على {selected.length} فرع
                                    <span className="text-muted-foreground ms-1 text-xs font-normal">
                                        ({selectedLinked.length} تعديل • {selectedNew} ربط جديد)
                                    </span>
                                </span>
                                <Button type="button" size="sm" variant="ghost" className="h-7 w-7 p-0" onClick={closeBulk}>
                                    <X className="size-3.5" />
                                </Button>
                            </div>

                            {attachedServices.length > 0 && (
                                <div className="bg-muted/40 space-y-1 rounded-md border p-2">
                                    <Label className="flex items-center gap-1.5 text-xs">
                                        <Copy className="size-3" />
                                        نسخ القيم من
                                    </Label>
                                    <select
                                        className="border-input bg-background h-8 w-full rounded-md border px-2 text-sm"
                                        value={copySourceId}
                                        onChange={(e) => applyBulkSource(Number(e.target.value))}
                                    >
                                        <option value={0}>— بلا نسخ (قيم افتراضية) —</option>
                                        {attachedServices.map((bs) => (
                                            <option key={bs.id} value={bs.id}>
                                                {branches.find((b) => b.id === bs.branchId)?.name ?? `فرع #${bs.branchId}`}
                                            </option>
                                        ))}
                                    </select>
                                    {selectedNew > 0 && (
                                        <p className="text-muted-foreground text-xs">الفروع الجديدة تأخذ من هنا كل حقل لم تختره أدناه.</p>
                                    )}
                                </div>
                            )}

                            <div className="space-y-1.5">
                                <Label className="text-xs">الحقول المراد تعديلها</Label>
                                <div className="flex flex-wrap gap-1.5">
                                    {(Object.keys(FIELD_GROUPS) as FieldGroup[]).map((group) => {
                                        const on = groups.includes(group);
                                        return (
                                            <button
                                                key={group}
                                                type="button"
                                                aria-pressed={on}
                                                onClick={() => toggleGroup(group)}
                                                className={`rounded-full border px-2.5 py-1 text-xs transition-colors ${
                                                    on ? 'border-primary bg-primary text-primary-foreground' : 'bg-background hover:bg-muted'
                                                }`}
                                            >
                                                {FIELD_GROUPS[group].label}
                                            </button>
                                        );
                                    })}
                                </div>
                                {selectedLinked.length > 0 && (
                                    <p className="text-muted-foreground text-xs">الفروع المربوطة تحتفظ بقيمها في كل حقل غير مختار.</p>
                                )}
                            </div>

                            {groups.length > 0 && (
                                <div className="space-y-3 border-t pt-3">
                                    <BranchServiceFields
                                        data={bulkForm.data}
                                        errors={bulkForm.errors}
                                        setData={bulkForm.setData as (key: string, value: number | boolean | string | string[] | null) => void}
                                        show={(group) => groups.includes(group)}
                                    />
                                </div>
                            )}

                            <InputError
                                message={
                                    bulkForm.errors.branch_ids ?? Object.entries(bulkForm.errors).find(([k]) => k.startsWith('branch_ids.'))?.[1]
                                }
                            />

                            <div className="flex justify-end gap-2">
                                <Button type="button" size="sm" variant="outline" onClick={closeBulk} disabled={bulkForm.processing}>
                                    إلغاء
                                </Button>
                                <Button type="submit" size="sm" disabled={bulkForm.processing || (groups.length === 0 && selectedNew === 0)}>
                                    {bulkForm.processing ? 'جاري التطبيق...' : 'تطبيق'}
                                </Button>
                            </div>
                        </form>
                    )}

                    {branches.map((branch) => {
                        const service = attachedServices.find((bs) => bs.branchId === branch.id);
                        const isAttached = !!service;
                        const isEditing = activeAction?.type === 'edit' && service && activeAction.serviceId === service.id;
                        const isAttaching = activeAction?.type === 'attach' && activeAction.branchId === branch.id;

                        return (
                            <div key={branch.id} className="bg-card rounded-lg border p-3 transition-colors">
                                {/* Row header */}
                                <div className="flex items-center justify-between gap-2">
                                    <label className="flex cursor-pointer items-center gap-2 text-sm font-medium">
                                        <Checkbox
                                            checked={selected.includes(branch.id)}
                                            onCheckedChange={(checked) => toggleSelected(branch.id, checked === true)}
                                        />
                                        {branch.name}
                                    </label>

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
                                                        onClick={() => setAgentsService(service)}
                                                    >
                                                        <UserRoundCheck className="size-3" />
                                                        المناديب
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
                                        {/* تاسك 79: نسخ شروط فرعٍ مربوط بدل كتابة ثلاث عشرة خانة لكل فرع.
                                            الفروع غير المربوطة لا تظهر في القائمة — لا شروط لها تُنسخ. */}
                                        {attachedServices.length > 0 && (
                                            <div className="bg-muted/40 space-y-1 rounded-md border p-2">
                                                <Label className="flex items-center gap-1.5 text-xs">
                                                    <Copy className="size-3" />
                                                    نسخ الشروط من
                                                </Label>
                                                <select
                                                    className="border-input bg-background h-8 w-full rounded-md border px-2 text-sm"
                                                    value={copySourceId}
                                                    onChange={(e) => applyCopySource(Number(e.target.value))}
                                                >
                                                    <option value={0}>— بلا نسخ (خانات فارغة) —</option>
                                                    {attachedServices.map((bs) => (
                                                        <option key={bs.id} value={bs.id}>
                                                            {branches.find((b) => b.id === bs.branchId)?.name ?? `فرع #${bs.branchId}`}
                                                        </option>
                                                    ))}
                                                </select>
                                                {copySourceId > 0 && (
                                                    <p className="text-muted-foreground text-xs">
                                                        نُسخت الشروط من فرع{' '}
                                                        {branches.find(
                                                            (b) => b.id === attachedServices.find((bs) => bs.id === copySourceId)?.branchId,
                                                        )?.name ?? '—'}{' '}
                                                        — راجعها قبل الحفظ. عمولات الموظفين لا تُنسخ.
                                                    </p>
                                                )}
                                            </div>
                                        )}

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

            <BranchServiceAgentsModal
                key={agentsService?.id ?? 'agents'}
                open={agentsService !== null}
                onOpenChange={(nextOpen) => !nextOpen && setAgentsService(null)}
                branchServiceId={agentsService?.id ?? null}
                serviceName={template?.name ?? ''}
                measured={isMeasured(agentsService?.pricingType ?? 'unit')}
                agents={agentsService ? (branchAgents[agentsService.branchId] ?? []) : []}
                current={agentsService ? (agentCommissions[agentsService.id] ?? []) : []}
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
        pricing_type: ServicePricingType;
        price_per_sqm: number;
        agent_commission_per_sqm: number;
        note_examples: string[];
        has_materials: boolean;
        materials_cost: number;
        is_active: boolean;
    };
    errors: Partial<Record<string, string>>;
    setData: (key: string, value: number | boolean | string | string[] | null) => void;
    /** التطبيق الجماعي يعرض المجموعات المختارة وحدها؛ بدونه تظهر كلها. */
    show?: (group: FieldGroup) => boolean;
}

function BranchServiceFields({ data, errors, setData, show = () => true }: FieldsProps) {
    return (
        <>
            <div className="grid grid-cols-2 gap-3">
                {show('base_commission_pct') && (
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
                )}

                {show('max_discount_pct') && (
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
                )}
            </div>

            {show('pricing') && (
                <>
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
                                {/* تاسك 80: بُعدٌ واحد — نقطة البيع تطلب الطول وحده. */}
                                <option value="linear">بالمتر الطولي</option>
                            </select>
                            <InputError message={errors.pricing_type} />
                        </div>

                        {data.pricing_type !== 'unit' && (
                            <div className="space-y-1">
                                <Label className="text-xs">
                                    {data.pricing_type === 'linear' ? 'سعر المتر الطولي (ر.س)' : 'سعر المتر المربع (ر.س)'}{' '}
                                    <span className="text-destructive">*</span>
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

                    {data.pricing_type !== 'unit' && (
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
                </>
            )}

            {show('price_bounds') && (
                <>
                    {/* سقف سعر البيع — يمنع الموظف من تجاوزه، وفارغه يترك السعر مفتوحاً */}
                    <div className="space-y-1">
                        <Label className="text-xs">
                            {data.pricing_type === 'unit' ? 'أعلى سعر للبيع (ر.س)' : `أعلى سعر ${meterLabel(data.pricing_type)} (ر.س)`} — شامل الضريبة
                        </Label>
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            className="h-8 text-sm"
                            placeholder="فارغة = السعر مفتوح"
                            value={data.max_selling_price ?? ''}
                            onChange={(e) =>
                                setData('max_selling_price', e.target.value === '' ? null : Math.max(0, parseFloat(e.target.value) || 0))
                            }
                            dir="ltr"
                        />
                        <InputError message={errors.max_selling_price} />
                    </div>

                    {/* أرضية سعر البيع (تاسك 64) — مرآة السقف أعلاه */}
                    <div className="space-y-1">
                        <Label className="text-xs">
                            {data.pricing_type === 'unit' ? 'أقل سعر للبيع (ر.س)' : `أقل سعر ${meterLabel(data.pricing_type)} (ر.س)`} — شامل الضريبة
                        </Label>
                        <Input
                            type="number"
                            step="0.01"
                            min="0"
                            className="h-8 text-sm"
                            placeholder="فارغة = لا حدّ أدنى"
                            value={data.min_selling_price ?? ''}
                            onChange={(e) =>
                                setData('min_selling_price', e.target.value === '' ? null : Math.max(0, parseFloat(e.target.value) || 0))
                            }
                            dir="ltr"
                        />
                        <InputError message={errors.min_selling_price} />
                    </div>
                </>
            )}

            {show('note_examples') && (
                <NoteExamplesField
                    value={data.note_examples}
                    onChange={(next) => setData('note_examples', next)}
                    error={errors.note_examples}
                    compact
                    idPrefix="bs-manage"
                />
            )}

            {/* الخامات: تكلفة افتراضية تُعبّئ خانة السطر في نقطة البيع وتُخصم
                من أساس عمولة الموظف وحده — لا تظهر للعميل ولا تدخل الإجمالي. */}
            {show('materials') && data.has_materials && (
                <div className="space-y-1">
                    {/* وحدة المبلغ تتبع نوع التسعير (تاسك 63). */}
                    <Label className="text-xs">
                        {data.pricing_type === 'unit' ? 'تكلفة الخامات للوحدة (ر.س)' : `تكلفة الخامات ${meterLabel(data.pricing_type)} (ر.س)`} — بلا
                        ضريبة
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
                    {/* تاسك 77: الصفر معناه «تُحدَّد وقت البيع» لا «بلا خامات». */}
                    <p className="text-muted-foreground text-xs">اتركها صفراً ليُدخلها الموظف مع كل فاتورة.</p>
                </div>
            )}

            <div className="flex items-center gap-6">
                {show('materials') && (
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
                )}

                {show('is_active') && (
                    <div className="flex items-center gap-2">
                        <Checkbox id="bs-active" checked={data.is_active} onCheckedChange={(checked) => setData('is_active', checked === true)} />
                        <Label htmlFor="bs-active" className="cursor-pointer text-xs">
                            نشط
                        </Label>
                    </div>
                )}
            </div>
        </>
    );
}
