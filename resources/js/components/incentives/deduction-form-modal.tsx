import { store, update } from '@/actions/App/Http/Controllers/EmployeeDeductionController';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type DeductionReasonOption, type EmployeeDeduction, type EmployeeOption } from '@/types/incentive';
import { useForm } from '@inertiajs/react';
import InputError from '../input-error';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employees: EmployeeOption[];
    reasons: DeductionReasonOption[];
    /** تاسك 126: القيد المُعدَّل، أو null للتسجيل. */
    editing?: EmployeeDeduction | null;
}

/**
 * تاسك 74: تسجيل حسم على موظف.
 *
 * تاسك 126: والنافذة نفسها تعدّله — القيمة والسبب والتاريخ. والموظف المحسوم
 * عليه يُقفل في التعديل: نقله إلى ذمّةٍ أخرى حذفٌ وتسجيلٌ جديد، لا تعديل.
 */
export default function DeductionFormModal({ open, onOpenChange, employees, reasons, editing = null }: Props) {
    const isEdit = editing !== null;
    const today = new Date().toISOString().slice(0, 10);

    // الحقول تُبتدأ مرةً واحدة عند التركيب — والشاشة تُعيد تركيب النافذة بـ`key`
    // لكل قيدٍ تفتحه، فلا حاجة إلى تصفيرٍ يدويّ بعد كل فتحة.
    const { data, setData, post, patch, processing, errors } = useForm({
        user_id: editing ? editing.userId.toString() : '',
        amount: editing ? editing.amount.toString() : '',
        reason: editing?.reason ?? '',
        reason_note: editing?.reasonNote ?? '',
        deducted_at: editing?.deductedAtDate ?? today,
        notes: editing?.notes ?? '',
    });

    // «حالات أخرى» وحدها تطالب بشرح — والخادم يفرضه كذلك بـrequired_if.
    const needsNote = reasons.find((r) => r.value === data.reason)?.requiresNote ?? false;

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        const done = { preserveScroll: true, onSuccess: () => onOpenChange(false) };

        if (editing) {
            patch(update.url(editing.id), done);
        } else {
            post(store.url(), done);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'تعديل حسم' : 'تسجيل حسم على موظف'}</DialogTitle>
                </DialogHeader>

                <form id="deduction-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="space-y-1">
                        <Label htmlFor="deduction-employee">الموظف</Label>
                        <Select value={data.user_id} onValueChange={(val) => setData('user_id', val)} disabled={isEdit}>
                            <SelectTrigger id="deduction-employee">
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
                        {isEdit && (
                            <p className="text-muted-foreground text-xs">
                                الموظف لا يُغيَّر — نقل الحسم إلى غيره يكون بحذف هذا القيد وتسجيل قيدٍ جديد.
                            </p>
                        )}
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="deduction-amount">قيمة الحسم (ر.س)</Label>
                        <Input
                            id="deduction-amount"
                            type="number"
                            min="0"
                            step="0.01"
                            value={data.amount}
                            onChange={(e) => setData('amount', e.target.value)}
                            placeholder="0.00"
                            dir="ltr"
                        />
                        <InputError message={errors.amount} />
                    </div>

                    <div className="space-y-1">
                        <Label htmlFor="deduction-reason">سبب الحسم</Label>
                        <Select value={data.reason} onValueChange={(val) => setData('reason', val)}>
                            <SelectTrigger id="deduction-reason">
                                <SelectValue placeholder="اختر السبب" />
                            </SelectTrigger>
                            <SelectContent>
                                {reasons.map((reason) => (
                                    <SelectItem key={reason.value} value={reason.value}>
                                        {reason.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.reason} />
                    </div>

                    {needsNote && (
                        <div className="space-y-1">
                            <Label htmlFor="deduction-reason-note">شرح السبب</Label>
                            <Input
                                id="deduction-reason-note"
                                value={data.reason_note}
                                onChange={(e) => setData('reason_note', e.target.value)}
                                placeholder="اكتب الحالة التي استوجبت الحسم"
                            />
                            <InputError message={errors.reason_note} />
                        </div>
                    )}

                    {isEdit && (
                        <div className="space-y-1">
                            <Label htmlFor="deduction-date">التاريخ</Label>
                            <Input
                                id="deduction-date"
                                type="date"
                                value={data.deducted_at}
                                onChange={(e) => setData('deducted_at', e.target.value)}
                                dir="ltr"
                            />
                            <InputError message={errors.deducted_at} />
                        </div>
                    )}

                    <div className="space-y-1">
                        <Label htmlFor="deduction-notes">ملاحظات</Label>
                        <textarea
                            id="deduction-notes"
                            rows={2}
                            value={data.notes}
                            onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setData('notes', e.target.value)}
                            placeholder="اختياري"
                            className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[64px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                        />
                        <InputError message={errors.notes} />
                    </div>

                    <p className="text-muted-foreground text-xs">
                        الحسم لا يمسّ عمولة الموظف ولا مكافأته: يُعرض بجانبهما في كشفه. وكلُّ تعديلٍ عليه مكتوبٌ في
                        سجلّه.
                    </p>

                    {/* تاسك 126: سجلّ التعديلات — القديم ⇒ الجديد، من ومتى. */}
                    {editing && editing.history.length > 0 && (
                        <div className="space-y-2 border-t pt-3">
                            <p className="text-sm font-semibold">سجلّ التعديلات</p>
                            {editing.history.map((entry) => (
                                <div key={entry.id} className="text-muted-foreground text-xs">
                                    <p className="font-medium">
                                        {entry.byName ?? '—'} — {entry.at}
                                    </p>
                                    {entry.changes.map((change) => (
                                        <p key={change.label}>
                                            {change.label}: {change.old} ⇐ {change.new}
                                        </p>
                                    ))}
                                </div>
                            ))}
                        </div>
                    )}
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="deduction-form" disabled={processing}>
                        {processing ? 'جاري الحفظ...' : isEdit ? 'حفظ التعديلات' : 'تسجيل الحسم'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
