import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type BankAccountOption } from '@/types/expense';

/** تاسك 187: الحساب يبقى للتحويل وحده — اختيار النقدي يمسحه. */
export function keepAccount(paidFrom: string, accountId: string): string {
    return paidFrom === 'company_transfer' ? accountId : '';
}

/** تاسك 187 — الحساب الذي خرج منه التحويل؛ في نافذة المصروف وفي «تسليم مبلغ التوصيل». */
export default function BankAccountField({
    accounts,
    value,
    onChange,
    error,
}: {
    accounts: BankAccountOption[];
    value: string;
    onChange: (value: string) => void;
    error?: string;
}) {
    return (
        <div className="space-y-1">
            <Label htmlFor="bank-account">الحساب البنكي</Label>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger id="bank-account">
                    <SelectValue placeholder="اختر الحساب" />
                </SelectTrigger>
                <SelectContent>
                    {accounts.map((a) => (
                        <SelectItem key={a.id} value={String(a.id)}>
                            {a.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            {accounts.length === 0 && <p className="text-muted-foreground text-xs">لا توجد حسابات بنكية للفرع — يضيفها مدير الفرع من الإعدادات.</p>}
            <InputError message={error} />
        </div>
    );
}
