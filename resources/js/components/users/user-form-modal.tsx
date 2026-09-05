import { store, update } from '@/actions/App/Http/Controllers/UserController';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { RichTextField } from '@/components/ui/rich-text-field';
import UserAttachmentsSection from '@/components/users/user-attachments-section';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type BranchOption, type ManagedUser, type RoleOption } from '@/types/user';
import { useForm } from '@inertiajs/react';
import InputError from '../input-error';
import { useEffect } from 'react';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    user?: ManagedUser;
    roles: RoleOption[];
    branches: BranchOption[];
    isSuperAdmin: boolean;
}

export default function UserFormModal({ open, onOpenChange, user, roles, branches, isSuperAdmin }: Props) {
    const isEdit = !!user;

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name:                     user?.name ?? '',
        username:                 user?.username ?? '',
        email:                    user?.email ?? '',
        phone:                    user?.phone ?? '',
        password:                 '',
        password_confirmation:    '',
        role:                     user?.role ?? '',
        branch_id:                user?.branchId?.toString() ?? '',
        salary:                   user?.salary?.toString() ?? '0',
        base_commission_pct:      user?.baseCommissionPct?.toString() ?? '0',
        referral_commission_pct:  user?.referralCommissionPct?.toString() ?? '0',
        joined_date:              user?.joinedDate ?? '',
        notes:                    user?.notes ?? '',
        is_active:                user?.isActive ?? true,
    });

    useEffect(() => {
        if (user) {
            setData({
                name: user.name ?? '',
                username: user.username ?? '',
                email: user.email ?? '',
                phone: user.phone ?? '',
                password: '',
                password_confirmation: '',
                role: user.role ?? '',
                branch_id: user.branchId?.toString() ?? '',
                salary: user.salary?.toString() ?? '0',
                base_commission_pct: user.baseCommissionPct?.toString() ?? '0',
                referral_commission_pct: user.referralCommissionPct?.toString() ?? '0',
                joined_date: user.joinedDate ?? '',
                notes: user.notes ?? '',
                is_active: user.isActive ?? true,
            });
        } else {
            reset();
        }
    }, [user, open]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        if (isEdit) {
            put(update.url(user), {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => { onOpenChange(false); reset(); },
            });
        } else {
            post(store.url(), {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => { onOpenChange(false); reset(); },
            });
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'تعديل مستخدم' : 'إضافة مستخدم'}</DialogTitle>
                </DialogHeader>

                <form id="user-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="user-name">الاسم</Label>
                            <Input
                                id="user-name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="الاسم الكامل"
                                autoFocus
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="user-username">اسم المستخدم</Label>
                            <Input
                                id="user-username"
                                value={data.username}
                                onChange={(e) => setData('username', e.target.value)}
                                placeholder="username"
                                dir="ltr"
                            />
                            <InputError message={errors.username} />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="user-email">البريد الإلكتروني</Label>
                            <Input
                                id="user-email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="email@example.com"
                                dir="ltr"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="user-phone">رقم الجوال</Label>
                            <Input
                                id="user-phone"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                                placeholder="05xxxxxxxx"
                                dir="ltr"
                            />
                            <InputError message={errors.phone} />
                        </div>
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="user-password">
                                كلمة المرور {isEdit && <span className="text-xs text-muted-foreground">(اتركها فارغة لعدم التغيير)</span>}
                            </Label>
                            <PasswordInput
                                id="user-password"
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
                            <Label htmlFor="user-password-confirm">تأكيد كلمة المرور</Label>
                            <PasswordInput
                                id="user-password-confirm"
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
                            <Label htmlFor="user-role">الدور</Label>
                            <Select
                                value={data.role}
                                onValueChange={(val) => setData('role', val)}
                            >
                                <SelectTrigger id="user-role">
                                    <SelectValue placeholder="اختر الدور" />
                                </SelectTrigger>
                                <SelectContent>
                                    {roles.map((r) => (
                                        <SelectItem key={r.value} value={r.value}>
                                            {r.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.role} />
                        </div>

                        {isSuperAdmin && (
                            <div className="space-y-1">
                                <Label htmlFor="user-branch">الفرع</Label>
                                <Select
                                    value={data.branch_id || 'none'}
                                    onValueChange={(val) => setData('branch_id', val === 'none' ? '' : val)}
                                >
                                    <SelectTrigger id="user-branch">
                                        <SelectValue placeholder="اختر الفرع" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">بدون فرع</SelectItem>
                                        {branches.map((b) => (
                                            <SelectItem key={b.id} value={b.id.toString()}>
                                                {b.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.branch_id} />
                            </div>
                        )}
                    </div>

                    <div className="grid grid-cols-3 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="user-salary">الراتب (ر.س)</Label>
                            <Input
                                id="user-salary"
                                type="number"
                                min="0"
                                step="0.01"
                                value={data.salary}
                                onChange={(e) => setData('salary', e.target.value)}
                                dir="ltr"
                            />
                            <InputError message={errors.salary} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="user-base-comm">العمولة الأساسية %</Label>
                            <Input
                                id="user-base-comm"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                value={data.base_commission_pct}
                                onChange={(e) => setData('base_commission_pct', e.target.value)}
                                dir="ltr"
                            />
                            <p className="text-muted-foreground text-[11px] leading-tight">
                                تُسجَّل تلقائياً كنسبة هذا الموظف على أي خدمة تُضاف لفرعه لاحقاً، ويمكن تعديلها لكل خدمة على حدة.
                            </p>
                            <InputError message={errors.base_commission_pct} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="user-ref-comm">عمولة الإحالة %</Label>
                            <Input
                                id="user-ref-comm"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                value={data.referral_commission_pct}
                                onChange={(e) => setData('referral_commission_pct', e.target.value)}
                                dir="ltr"
                            />
                            <InputError message={errors.referral_commission_pct} />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="user-joined">تاريخ الالتحاق</Label>
                        <Input
                            id="user-joined"
                            type="date"
                            value={data.joined_date}
                            onChange={(e) => setData('joined_date', e.target.value)}
                            dir="ltr"
                        />
                        <InputError message={errors.joined_date} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="user-notes">ملاحظات</Label>
                        <RichTextField
                            id="user-notes"
                            value={data.notes}
                            onChange={(html) => setData('notes', html)}
                            placeholder="ملاحظات إدارية عن المستخدم..."
                            disabled={processing}
                        />
                        <InputError message={errors.notes} />
                    </div>

                    {/* المرفقات تُرفع بمسارها المستقلّ فور اختيارها، فلا تُعرض
                        إلا لمستخدمٍ محفوظ — ولا معرّف قبل الحفظ أصلاً. */}
                    {isEdit && user && <UserAttachmentsSection userId={user.id} canManage />}

                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="user-is-active"
                            checked={data.is_active}
                            onCheckedChange={(checked) => setData('is_active', checked === true)}
                        />
                        <Label htmlFor="user-is-active" className="cursor-pointer">
                            نشط
                        </Label>
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
                    <Button type="submit" form="user-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : isEdit ? 'حفظ التعديلات' : 'إضافة'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
