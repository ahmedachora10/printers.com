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
import { type Agent, type AgentDiscountMode, type AgentType, type EnumOption } from '@/types/agent';
import { useForm } from '@inertiajs/react';
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

export default function AgentFormModal({ open, onOpenChange, agent, agentTypes, discountModes, branches }: Props) {
    const isEdit = !!agent;
    const isSuperAdmin = Array.isArray(branches);

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: agent?.name ?? '',
        username: agent?.username ?? '',
        email: agent?.email ?? '',
        phone: agent?.phone ?? '',
        password: '',
        password_confirmation: '',
        branch_id: agent?.branchId?.toString() ?? (branches?.[0]?.id?.toString() ?? ''),
        is_active: agent?.isActive ?? true,
        agent_type: (agent?.agentType?.value ?? 'individual') as AgentType,
        discount_mode: (agent?.discountMode?.value ?? 'discount') as AgentDiscountMode,
        rate: agent?.rate?.toString() ?? '',
        commercial_reg_no: agent?.commercialRegNo ?? '',
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
                discount_mode: (agent.discountMode?.value ?? 'discount') as AgentDiscountMode,
                rate: agent.rate?.toString() ?? '',
                commercial_reg_no: agent.commercialRegNo ?? '',
            });
        } else {
            reset();
        }
    }, [agent, open]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

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
                    <DialogTitle>{isEdit ? 'تعديل وكيل' : 'إضافة وكيل'}</DialogTitle>
                </DialogHeader>

                <form id="agent-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    {isSuperAdmin && (
                        <div className="space-y-1">
                            <Label htmlFor="agent-branch">الفرع</Label>
                            <Select
                                value={data.branch_id}
                                onValueChange={(val) => setData('branch_id', val)}
                            >
                                <SelectTrigger id="agent-branch">
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
                            <InputError message={errors.branch_id} />
                        </div>
                    )}

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="agent-name">الاسم</Label>
                            <Input
                                id="agent-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="اسم الوكيل"
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

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="agent-mode">نمط العمولة</Label>
                            <Select
                                value={data.discount_mode}
                                onValueChange={(val) => setData('discount_mode', val as AgentDiscountMode)}
                            >
                                <SelectTrigger id="agent-mode">
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
                            <InputError message={errors.discount_mode} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="agent-rate">النسبة (%)</Label>
                            <Input
                                id="agent-rate"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                value={data.rate}
                                onChange={(e) => setData('rate', e.target.value)}
                                placeholder="0.00"
                                dir="ltr"
                            />
                            <InputError message={errors.rate} />
                        </div>
                    </div>

                    <div className="flex items-center justify-between rounded-lg border px-4 py-3">
                        <Label htmlFor="agent-active">الوكيل نشط</Label>
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
