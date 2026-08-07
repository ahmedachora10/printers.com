import { store, update } from '@/actions/App/Http/Controllers/AgentController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { type Agent, type AgentDiscountMode, type AgentDiscountType, type AgentType, type EnumOption } from '@/types/agent';
import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect } from 'react';
import InputError from '../input-error';

interface Branch {
    id: number;
    name: string;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    agent?: Agent;
    agentTypes: EnumOption[];
    discountModes: EnumOption[];
    branches?: Branch[] | null;
}

/**
 * One agent↔branch link as the form holds it, before it is posted. The index
 * signature is what makes the row assignable to Inertia's FormDataConvertible.
 */
interface BranchTermRow {
    [key: string]: string;
    branch_id: string;
    discount_mode: AgentDiscountMode;
    discount_type: AgentDiscountType;
    rate: string;
}

function blankRow(branchId?: number): BranchTermRow {
    return {
        branch_id: branchId?.toString() ?? '',
        discount_mode: 'discount',
        discount_type: 'percentage',
        rate: '',
    };
}

function rowsFor(agent: Agent | undefined, branches: Branch[] | null | undefined): BranchTermRow[] {
    if (agent?.branches?.length) {
        return agent.branches.map((b) => ({
            branch_id: b.branchId.toString(),
            discount_mode: b.discountMode ?? 'discount',
            discount_type: b.discountType ?? 'percentage',
            rate: b.rate?.toString() ?? '',
        }));
    }

    return [blankRow(branches?.[0]?.id)];
}

export default function AgentFormModal({ open, onOpenChange, agent, agentTypes, discountModes, branches }: Props) {
    const isEdit = !!agent;
    const isSuperAdmin = Array.isArray(branches);

    const { data, setData, post, put, processing, errors, reset, transform } = useForm({
        name: agent?.name ?? '',
        username: agent?.username ?? '',
        email: agent?.email ?? '',
        phone: agent?.phone ?? '',
        password: '',
        password_confirmation: '',
        branch_id: agent?.branchId?.toString() ?? (branches?.[0]?.id?.toString() ?? ''),
        is_active: agent?.isActive ?? true,
        agent_type: (agent?.agentType?.value ?? 'individual') as AgentType,
        commercial_reg_no: agent?.commercialRegNo ?? '',
        // The terms are per branch; an agent may work with several at once.
        branches: rowsFor(agent, branches),
    });

    useEffect(() => {
        if (agent) {
            setData({
                name: agent.name ?? '',
                username: agent.username ?? '',
                email: agent.email ?? '',
                phone: agent.phone ?? '',
                password: '',
                password_confirmation: '',
                branch_id: agent.branchId?.toString() ?? (branches?.[0]?.id?.toString() ?? ''),
                is_active: agent.isActive ?? true,
                agent_type: (agent.agentType?.value ?? 'individual') as AgentType,
                commercial_reg_no: agent.commercialRegNo ?? '',
                branches: rowsFor(agent, branches),
            });
        } else {
            reset();
        }
    }, [agent, open]);

    function updateRow(index: number, patch: Partial<BranchTermRow>) {
        setData(
            'branches',
            data.branches.map((row, i) => (i === index ? ({ ...row, ...patch } as BranchTermRow) : row)),
        );
    }

    function addRow() {
        const taken = new Set(data.branches.map((r) => r.branch_id));
        const next = branches?.find((b) => !taken.has(b.id.toString()));
        setData('branches', [...data.branches, blankRow(next?.id)]);
    }

    function removeRow(index: number) {
        setData('branches', data.branches.filter((_, i) => i !== index));
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        // The flat profile fields mirror the primary branch's terms — they are
        // the defaults an operator sees pre-filled when linking a new branch.
        transform((payload) => ({
            ...payload,
            discount_mode: payload.branches[0]?.discount_mode ?? 'discount',
            discount_type: payload.branches[0]?.discount_type ?? 'percentage',
            rate: payload.branches[0]?.rate ?? '0',
        }));

        if (isEdit) {
            put(update.url(agent), {
                preserveScroll: true,
                onSuccess: () => { onOpenChange(false); reset(); },
            });
        } else {
            post(store.url(), {
                preserveScroll: true,
                onSuccess: () => { onOpenChange(false); reset(); },
            });
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-xl max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'تعديل مندوب' : 'إضافة مندوب'}</DialogTitle>
                </DialogHeader>

                <form id="agent-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="agent-name">الاسم</Label>
                            <Input
                                id="agent-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="اسم المندوب"
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="agent-type">النوع</Label>
                            <Select
                                value={data.agent_type}
                                onValueChange={(val) => setData('agent_type', val as AgentType)}
                            >
                                <SelectTrigger id="agent-type">
                                    <SelectValue placeholder="اختر النوع" />
                                </SelectTrigger>
                                <SelectContent>
                                    {agentTypes.map((t) => (
                                        <SelectItem key={t.value} value={t.value}>
                                            {t.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.agent_type} />
                        </div>
                    </div>

                    {/* Login credentials — agents sign in to the portal with these. */}
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="agent-username">اسم المستخدم</Label>
                            <Input
                                id="agent-username"
                                value={data.username}
                                onChange={(e) => setData('username', e.target.value)}
                                placeholder="username"
                                dir="ltr"
                            />
                            <InputError message={errors.username} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="agent-email">البريد الإلكتروني</Label>
                            <Input
                                id="agent-email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="email@example.com"
                                dir="ltr"
                            />
                            <InputError message={errors.email} />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="agent-password">
                                {isEdit ? 'كلمة المرور (اتركها فارغة لعدم التغيير)' : 'كلمة المرور'}
                            </Label>
                            <PasswordInput
                                id="agent-password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="••••••••"
                                dir="ltr"
                                autoComplete="new-password"
                                withGenerator
                                onGenerate={(password) => {
                                    setData('password', password);
                                    setData('password_confirmation', password);
                                }}
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="agent-password-confirm">تأكيد كلمة المرور</Label>
                            <PasswordInput
                                id="agent-password-confirm"
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                placeholder="••••••••"
                                dir="ltr"
                                autoComplete="new-password"
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="agent-phone">رقم الهاتف</Label>
                            <Input
                                id="agent-phone"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                                placeholder="05XXXXXXXX"
                                dir="ltr"
                            />
                            <InputError message={errors.phone} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="agent-cr">السجل التجاري</Label>
                            <Input
                                id="agent-cr"
                                value={data.commercial_reg_no}
                                onChange={(e) => setData('commercial_reg_no', e.target.value)}
                                placeholder="اختياري"
                                dir="ltr"
                            />
                            <InputError message={errors.commercial_reg_no} />
                        </div>
                    </div>

                    {/* Branch links: each branch negotiates its own mode and rate,
                        and the agent may only be invoiced in the branches listed here. */}
                    <div className="space-y-3 rounded-lg border p-4">
                        <div className="flex items-center justify-between">
                            <Label className="text-base">الفروع وشروط كل فرع</Label>
                            {isSuperAdmin && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addRow}
                                    disabled={data.branches.length >= (branches?.length ?? 0)}
                                >
                                    <Plus className="size-4" /> إضافة فرع
                                </Button>
                            )}
                        </div>

                        {data.branches.map((row, index) => (
                            <div key={index} className="space-y-2 rounded-md border bg-muted/30 p-3">
                                {isSuperAdmin && (
                                    <div className="flex items-end gap-2">
                                        <div className="flex-1 space-y-1">
                                            <Label htmlFor={`agent-branch-${index}`}>الفرع</Label>
                                            <Select
                                                value={row.branch_id}
                                                onValueChange={(val) => updateRow(index, { branch_id: val })}
                                            >
                                                <SelectTrigger id={`agent-branch-${index}`}>
                                                    <SelectValue placeholder="اختر الفرع" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {branches!.map((b) => (
                                                        <SelectItem key={b.id} value={b.id.toString()}>
                                                            {b.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        {data.branches.length > 1 && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                onClick={() => removeRow(index)}
                                                aria-label="إزالة الفرع"
                                            >
                                                <Trash2 className="size-4 text-destructive" />
                                            </Button>
                                        )}
                                    </div>
                                )}
                                <InputError message={errors[`branches.${index}.branch_id` as keyof typeof errors]} />

                                <div className="grid grid-cols-3 gap-3">
                                    <div className="space-y-1">
                                        <Label htmlFor={`agent-mode-${index}`}>نمط العمولة</Label>
                                        <Select
                                            value={row.discount_mode}
                                            onValueChange={(val) => updateRow(index, { discount_mode: val as AgentDiscountMode })}
                                        >
                                            <SelectTrigger id={`agent-mode-${index}`}>
                                                <SelectValue placeholder="اختر النمط" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {discountModes.map((m) => (
                                                    <SelectItem key={m.value} value={m.value}>
                                                        {m.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-1">
                                        <Label htmlFor={`agent-discount-type-${index}`}>نوع الخصم</Label>
                                        <Select
                                            value={row.discount_type}
                                            onValueChange={(val) => updateRow(index, { discount_type: val as AgentDiscountType })}
                                        >
                                            <SelectTrigger id={`agent-discount-type-${index}`}>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="percentage">نسبة مئوية %</SelectItem>
                                                <SelectItem value="fixed">مبلغ ثابت ر.س</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="space-y-1">
                                        <Label htmlFor={`agent-rate-${index}`}>
                                            {row.discount_type === 'percentage' ? 'النسبة (%)' : 'المبلغ (ر.س)'}
                                        </Label>
                                        <Input
                                            id={`agent-rate-${index}`}
                                            type="number"
                                            min="0"
                                            max={row.discount_type === 'percentage' ? '100' : undefined}
                                            step="0.01"
                                            value={row.rate}
                                            onChange={(e) => updateRow(index, { rate: e.target.value })}
                                            placeholder="0.00"
                                            dir="ltr"
                                        />
                                    </div>
                                </div>
                                <InputError message={errors[`branches.${index}.rate` as keyof typeof errors]} />
                                <InputError message={errors[`branches.${index}.discount_mode` as keyof typeof errors]} />
                            </div>
                        ))}

                        <InputError message={errors.branches} />
                        <InputError message={errors.branch_id} />
                    </div>

                    <div className="flex items-center justify-between rounded-lg border px-4 py-3">
                        <Label htmlFor="agent-active">المندوب نشط</Label>
                        <Switch
                            id="agent-active"
                            checked={data.is_active}
                            onCheckedChange={(val) => setData('is_active', val)}
                        />
                    </div>
                </form>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        إلغاء
                    </Button>
                    <Button type="submit" form="agent-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : isEdit ? 'حفظ التعديلات' : 'إضافة'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
