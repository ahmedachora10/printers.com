import BankAccountController from '@/actions/App/Http/Controllers/BankAccountController';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router, useForm } from '@inertiajs/react';
import { Landmark, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

export interface BankAccount {
    id: number;
    branchId: number;
    branchName: string | null;
    name: string;
    bankName: string | null;
    ibanLast4: string | null;
}

interface Props {
    accounts: BankAccount[];
    branches: { id: number; name: string }[];
    isSuperAdmin: boolean;
    canManage: boolean;
}

/** تاسك 187 — حسابات الشركة البنكية لكل فرع؛ تُختار في مصروف «تحويل بنكي من حساب الشركة». */
export default function BankAccountsTab({ accounts, branches, isSuperAdmin, canManage }: Props) {
    const [editing, setEditing] = useState<BankAccount | 'new' | null>(null);

    const remove = (account: BankAccount) => {
        if (confirm(`حذف «${account.name}»؟ تبقى المصروفات السابقة تحمل اسمه.`)) {
            router.delete(BankAccountController.destroy.url(account.id), { preserveScroll: true });
        }
    };

    return (
        <div className="rounded-lg border">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b p-4">
                <div>
                    <h2 className="text-lg font-semibold">الحسابات البنكية</h2>
                    <p className="text-muted-foreground text-sm">تظهر عند تسجيل مصروف «تحويل بنكي من حساب الشركة»، واختيار الحساب إلزامي.</p>
                </div>
                {canManage && (
                    <Button size="sm" onClick={() => setEditing('new')}>
                        <Plus className="size-4" /> إضافة حساب
                    </Button>
                )}
            </div>
            {accounts.length === 0 ? (
                <p className="text-muted-foreground p-6 text-center text-sm">لا توجد حسابات بعد.</p>
            ) : (
                <ul className="divide-y">
                    {accounts.map((a) => (
                        <li key={a.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                            <div className="flex min-w-0 flex-wrap items-center gap-2">
                                <Landmark className="text-muted-foreground size-4 shrink-0" />
                                <span className="font-medium">{a.name}</span>
                                {a.bankName && <Badge variant="outline">{a.bankName}</Badge>}
                                {a.ibanLast4 && (
                                    <span className="text-muted-foreground text-sm tabular-nums" dir="ltr">
                                        ••{a.ibanLast4}
                                    </span>
                                )}
                                {isSuperAdmin && a.branchName && (
                                    <Badge variant="outline" className="text-muted-foreground">
                                        {a.branchName}
                                    </Badge>
                                )}
                            </div>
                            {canManage && (
                                <div className="flex items-center gap-2">
                                    <Button variant="outline" size="sm" aria-label="تعديل" onClick={() => setEditing(a)}>
                                        <Pencil className="h-3.5 w-3.5" />
                                    </Button>
                                    <Button variant="outline" size="sm" aria-label="حذف" className="text-destructive hover:text-destructive" onClick={() => remove(a)}>
                                        <Trash2 className="h-3.5 w-3.5" />
                                    </Button>
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {editing && (
                <AccountDialog account={editing === 'new' ? null : editing} branches={branches} isSuperAdmin={isSuperAdmin} onClose={() => setEditing(null)} />
            )}
        </div>
    );
}

function AccountDialog({
    account,
    branches,
    isSuperAdmin,
    onClose,
}: {
    account: BankAccount | null;
    branches: { id: number; name: string }[];
    isSuperAdmin: boolean;
    onClose: () => void;
}) {
    const form = useForm({
        branch_id: account?.branchId ?? (isSuperAdmin ? (branches[0]?.id ?? null) : null),
        name: account?.name ?? '',
        bank_name: account?.bankName ?? '',
        iban_last4: account?.ibanLast4 ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (account) form.put(BankAccountController.update.url(account.id), options);
        else form.post(BankAccountController.store.url(), options);
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{account ? 'تعديل حساب بنكي' : 'إضافة حساب بنكي'}</DialogTitle>
                </DialogHeader>
                <form id="bank-account-form" onSubmit={submit} className="space-y-4 py-2">
                    {isSuperAdmin && (
                        <div className="space-y-1">
                            <Label>الفرع</Label>
                            <Select value={form.data.branch_id ? String(form.data.branch_id) : ''} onValueChange={(v) => form.setData('branch_id', Number(v))}>
                                <SelectTrigger>
                                    <SelectValue placeholder="اختر الفرع" />
                                </SelectTrigger>
                                <SelectContent>
                                    {branches.map((b) => (
                                        <SelectItem key={b.id} value={String(b.id)}>
                                            {b.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.branch_id} />
                        </div>
                    )}
                    <div className="space-y-1">
                        <Label htmlFor="ba-name">اسم الحساب</Label>
                        <Input id="ba-name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="مثال: الحساب الرئيسي" autoFocus />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="ba-bank">البنك</Label>
                        <Input id="ba-bank" value={form.data.bank_name} onChange={(e) => form.setData('bank_name', e.target.value)} placeholder="اختياري — مثال: الراجحي" />
                        <InputError message={form.errors.bank_name} />
                    </div>
                    <div className="space-y-1">
                        <Label htmlFor="ba-iban">آخر 4 أرقام من الآيبان</Label>
                        <Input
                            id="ba-iban"
                            dir="ltr"
                            inputMode="numeric"
                            maxLength={4}
                            value={form.data.iban_last4}
                            onChange={(e) => form.setData('iban_last4', e.target.value.replace(/\D/g, ''))}
                            placeholder="اختياري"
                        />
                        <InputError message={form.errors.iban_last4} />
                    </div>
                </form>
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose} disabled={form.processing}>
                        إلغاء
                    </Button>
                    <Button type="submit" form="bank-account-form" disabled={form.processing}>
                        {form.processing ? 'جاري الحفظ...' : 'حفظ'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
