import { store, update } from '@/actions/App/Http/Controllers/IncentiveController';
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type EmployeeOption, type EnumOption, type IncentivePlan } from '@/types/incentive';
import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect } from 'react';
import InputError from '../input-error';

const MONTHS = [
    'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو',
    'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر',
];

type TierInput = { threshold: string; value: string };

const EMPTY_TIER: TierInput = { threshold: '', value: '' };

const toTierInputs = (plan: IncentivePlan): TierInput[] =>
    plan.tiers.map((t) => ({ threshold: t.threshold.toString(), value: t.value.toString() }));

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    plan?: IncentivePlan;
    employees: EmployeeOption[];
    bonusTypes: EnumOption[];
}

export default function PlanFormModal({ open, onOpenChange, plan, employees, bonusTypes }: Props) {
    const isEdit = !!plan;
    const now = new Date();

    const { data, setData, post, put, processing, errors, reset } = useForm({
        user_id: plan?.userId?.toString() ?? '',
        period_month: (plan?.periodMonth ?? now.getMonth() + 1).toString(),
        period_year: (plan?.periodYear ?? now.getFullYear()).toString(),
        bonus_type: plan?.bonusType ?? 'fixed',
        tiers: plan ? toTierInputs(plan) : [EMPTY_TIER],
        notes: plan?.notes ?? '',
    });

    useEffect(() => {
        if (plan) {
            setData({
                user_id: plan.userId?.toString() ?? '',
                period_month: (plan.periodMonth ?? now.getMonth() + 1).toString(),
                period_year: (plan.periodYear ?? now.getFullYear()).toString(),
                bonus_type: plan.bonusType ?? 'fixed',
                tiers: toTierInputs(plan),
                notes: plan.notes ?? '',
            });
        } else {
            reset();
        }
    }, [plan, open]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        const onSuccess = () => {
            onOpenChange(false);
            reset();
        };

        if (isEdit) {
            put(update.url(plan), { preserveScroll: true, onSuccess });
        } else {
            post(store.url(), { preserveScroll: true, onSuccess });
        }
    }

    const isPercentage = data.bonus_type === 'percentage';

    const setTier = (index: number, key: keyof TierInput, value: string) =>
        setData(
            'tiers',
            data.tiers.map((tier, i) => (i === index ? { ...tier, [key]: value } : tier)),
        );

    // أخطاء الشرائح تأتي بمفاتيح tiers.0.threshold… — تُجمع تحت القائمة.
    const tierErrors = Object.entries(errors as Record<string, string>)
        .filter(([key]) => key.startsWith('tiers'))
        .map(([, message]) => message);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'تعديل خطة الحوافز' : 'إضافة خطة حوافز'}</DialogTitle>
                </DialogHeader>

                <form id="incentive-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="space-y-1">
                        <Label htmlFor="incentive-employee">الموظف</Label>
                        <Select value={data.user_id} onValueChange={(val) => setData('user_id', val)}>
                            <SelectTrigger id="incentive-employee">
                                <SelectValue placeholder="اختر الموظف" />
                            </SelectTrigger>
                            <SelectContent>
                                {employees.map((emp) => (
                                    <SelectItem key={emp.id} value={emp.id.toString()}>
                                        {emp.name}
                                        {emp.branchName ? ` — ${emp.branchName}` : ''}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.user_id} />
                    </div>

                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-1">
                            <Label htmlFor="incentive-month">الشهر</Label>
                            <Select value={data.period_month} onValueChange={(val) => setData('period_month', val)}>
                                <SelectTrigger id="incentive-month">
                                    <SelectValue placeholder="الشهر" />
                                </SelectTrigger>
                                <SelectContent>
                                    {MONTHS.map((name, i) => (
                                        <SelectItem key={i + 1} value={(i + 1).toString()}>
                                            {name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.period_month} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="incentive-year">السنة</Label>
                            <Input
                                id="incentive-year"
                                type="number"
                                min="2020"
                                max="2100"
                                value={data.period_year}
                                onChange={(e) => setData('period_year', e.target.value)}
                                dir="ltr"
                            />
                            <InputError message={errors.period_year} />
                        </div>
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="incentive-bonus-type">نوع المكافأة</Label>
                        <Select value={data.bonus_type} onValueChange={(val) => setData('bonus_type', val)}>
                            <SelectTrigger id="incentive-bonus-type">
                                <SelectValue placeholder="النوع" />
                            </SelectTrigger>
                            <SelectContent>
                                {bonusTypes.map((t) => (
                                    <SelectItem key={t.value} value={t.value}>
                                        {t.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.bonus_type} />
                    </div>

                    {/* تاسك 105: شرائح — أعلى شريحة يبلغها الموظف هي وحدها ما يُصرف. */}
                    <div className="space-y-2">
                        <div className="grid grid-cols-[1fr_1fr_2.25rem] gap-2 text-sm font-medium">
                            <span>عند تحقيق (ر.س)</span>
                            <span>{isPercentage ? 'نسبة المكافأة (%)' : 'المكافأة (ر.س)'}</span>
                        </div>
                        {data.tiers.map((tier, i) => (
                            <div key={i} className="grid grid-cols-[1fr_1fr_2.25rem] gap-2">
                                <Input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={tier.threshold}
                                    onChange={(e) => setTier(i, 'threshold', e.target.value)}
                                    placeholder="0.00"
                                    aria-label={`عتبة الشريحة ${i + 1}`}
                                    dir="ltr"
                                />
                                <Input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={tier.value}
                                    onChange={(e) => setTier(i, 'value', e.target.value)}
                                    placeholder="0.00"
                                    aria-label={`مكافأة الشريحة ${i + 1}`}
                                    dir="ltr"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    className="text-destructive hover:text-destructive"
                                    disabled={data.tiers.length === 1}
                                    onClick={() => setData('tiers', data.tiers.filter((_, j) => j !== i))}
                                    aria-label={`حذف الشريحة ${i + 1}`}
                                >
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                        ))}
                        {data.tiers.length < 10 && (
                            <Button type="button" variant="outline" size="sm" onClick={() => setData('tiers', [...data.tiers, EMPTY_TIER])}>
                                <Plus className="size-4" /> إضافة شريحة
                            </Button>
                        )}
                        <p className="text-muted-foreground text-xs">
                            {isPercentage
                                ? 'النسبة من عتبة الشريحة المبلوغة — 1,000 بنسبة 1% و2,000 بنسبة 2%: من حقّق 2,900 يأخذ 40 ر.س.'
                                : 'تُصرف مكافأة أعلى شريحة بلغها الموظف وحدها.'}{' '}
                            {data.tiers.length > 1 && 'الخطة ذات الشرائح تُصرف بعد نهاية الشهر.'}
                        </p>
                        {[...new Set(tierErrors)].map((message) => (
                            <InputError key={message} message={message} />
                        ))}
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="incentive-notes">ملاحظات</Label>
                        <textarea
                            id="incentive-notes"
                            rows={2}
                            value={data.notes}
                            onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setData('notes', e.target.value)}
                            placeholder="اختياري"
                            className="flex min-h-[64px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                        />
                        <InputError message={errors.notes} />
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="incentive-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : isEdit ? 'حفظ التعديلات' : 'إضافة'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
