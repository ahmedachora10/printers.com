import {
    destroy as destroyBranchService,
    store as storeBranchService,
    update as updateBranchService,
} from '@/actions/App/Http/Controllers/BranchServiceController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type BranchService, type BranchServiceFormData, type BranchServiceUpdateData } from '@/types/branch-service';
import { type ServiceTemplate } from '@/types/service-template';
import { router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2, X } from 'lucide-react';
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
}

type ActiveAction =
    | { type: 'attach'; branchId: number }
    | { type: 'edit'; serviceId: number }
    | null;

export default function BranchServiceManageModal({ open, onOpenChange, template, branches }: Props) {
    const [activeAction, setActiveAction] = useState<ActiveAction>(null);

    const attachForm = useForm<BranchServiceFormData>({
        service_template_id: template?.id ?? 0,
        branch_id: 0,
        base_commission_pct: 0,
        max_discount_pct: 0,
        is_tahazir: false,
        is_active: true,
    });

    const editForm = useForm<BranchServiceUpdateData>({
        base_commission_pct: 0,
        max_discount_pct: 0,
        is_tahazir: false,
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
            is_tahazir: false,
            is_active: true,
        });
    }

    function startEdit(service: BranchService) {
        setActiveAction({ type: 'edit', serviceId: service.id });
        editForm.setData({
            base_commission_pct: service.baseCommissionPct,
            max_discount_pct: service.maxDiscountPct,
            is_tahazir: service.isTahazir,
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
                    {branches.length === 0 && (
                        <p className="py-8 text-center text-sm text-muted-foreground">
                            لا توجد فروع نشطة.
                        </p>
                    )}

                    {branches.map((branch) => {
                        const service = attachedServices.find((bs) => bs.branchId === branch.id);
                        const isAttached = !!service;
                        const isEditing =
                            activeAction?.type === 'edit' && service && activeAction.serviceId === service.id;
                        const isAttaching =
                            activeAction?.type === 'attach' && activeAction.branchId === branch.id;

                        return (
                            <div
                                key={branch.id}
                                className="rounded-lg border bg-card p-3 transition-colors"
                            >
                                {/* Row header */}
                                <div className="flex items-center justify-between gap-2">
                                    <span className="font-medium text-sm">{branch.name}</span>

                                    {!isEditing && !isAttaching && (
                                        <div className="flex items-center gap-1.5">
                                            {isAttached ? (
                                                <>
                                                    <span className="text-xs text-muted-foreground">
                                                        {service.baseCommissionPct}% عمولة •{' '}
                                                        {service.maxDiscountPct}% خصم
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
                                                        onClick={() => startEdit(service)}
                                                    >
                                                        <Pencil className="size-3" />
                                                        تعديل
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="h-7 gap-1 px-2 text-xs text-destructive hover:text-destructive"
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
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="h-7 w-7 p-0"
                                            onClick={cancelAction}
                                        >
                                            <X className="size-3.5" />
                                        </Button>
                                    )}
                                </div>

                                {/* Inline edit form */}
                                {isEditing && (
                                    <form
                                        onSubmit={handleEditSubmit}
                                        className="mt-3 space-y-3 border-t pt-3"
                                    >
                                        <BranchServiceFields
                                            data={editForm.data}
                                            errors={editForm.errors}
                                            setData={editForm.setData as (key: string, value: number | boolean) => void}
                                        />
                                        <div className="flex justify-end gap-2">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={cancelAction}
                                                disabled={editForm.processing}
                                            >
                                                إلغاء
                                            </Button>
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={editForm.processing}
                                            >
                                                {editForm.processing ? 'جاري الحفظ...' : 'حفظ'}
                                            </Button>
                                        </div>
                                    </form>
                                )}

                                {/* Inline attach form */}
                                {isAttaching && (
                                    <form
                                        onSubmit={handleAttachSubmit}
                                        className="mt-3 space-y-3 border-t pt-3"
                                    >
                                        <BranchServiceFields
                                            data={attachForm.data}
                                            errors={attachForm.errors}
                                            setData={attachForm.setData as (key: string, value: number | boolean) => void}
                                        />
                                        <div className="flex justify-end gap-2">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={cancelAction}
                                                disabled={attachForm.processing}
                                            >
                                                إلغاء
                                            </Button>
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={attachForm.processing}
                                            >
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
        </Dialog>
    );
}

interface FieldsProps {
    data: { base_commission_pct: number; max_discount_pct: number; is_tahazir: boolean; is_active: boolean };
    errors: Partial<Record<string, string>>;
    setData: (key: string, value: number | boolean) => void;
}

function BranchServiceFields({ data, errors, setData }: FieldsProps) {
    return (
        <>
            <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                    <Label className="text-xs">نسبة العمولة (%) <span className="text-destructive">*</span></Label>
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
                    <Label className="text-xs">الخصم الأقصى (%) <span className="text-destructive">*</span></Label>
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

            <div className="flex items-center gap-6">
                <div className="flex items-center gap-2">
                    <Checkbox
                        id="bs-tahazir"
                        checked={data.is_tahazir}
                        onCheckedChange={(checked) => setData('is_tahazir', checked === true)}
                    />
                    <Label htmlFor="bs-tahazir" className="cursor-pointer text-xs">
                        تحاضر
                    </Label>
                </div>

                <div className="flex items-center gap-2">
                    <Checkbox
                        id="bs-active"
                        checked={data.is_active}
                        onCheckedChange={(checked) => setData('is_active', checked === true)}
                    />
                    <Label htmlFor="bs-active" className="cursor-pointer text-xs">
                        نشط
                    </Label>
                </div>
            </div>
        </>
    );
}
