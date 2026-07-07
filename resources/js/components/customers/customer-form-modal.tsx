import { store, update } from '@/actions/App/Http/Controllers/CustomerController';
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
import { Switch } from '@/components/ui/switch';
import { type Customer } from '@/types/customer';
import { useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useEffect } from 'react';
import InputError from '../input-error';

interface Agent {
    id: number;
    name: string;
}

interface Branch {
    id: number;
    name: string;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    customer?: Customer;
    agents?: Agent[];
    branches?: Branch[];
    isSuperAdmin?: boolean;
}

export default function CustomerFormModal({ open, onOpenChange, customer, agents = [], branches = [], isSuperAdmin = false }: Props) {
    const isEdit = !!customer;

    const { data, setData, post, put, processing, errors, reset } = useForm({
        branch_id: customer?.branchId ? String(customer.branchId) : '',
        full_name: customer?.fullName ?? '',
        phone: customer?.phone ?? '',
        email: customer?.email ?? '',
        customer_type: customer?.customerType.value ?? 'individual',
        company_name: customer?.companyName ?? '',
        tax_number: customer?.taxNumber ?? '',
        credit_limit:
            customer?.creditLimit !== null && customer?.creditLimit !== undefined
                ? String(customer.creditLimit)
                : '',
        agent_id: customer?.agentId ? String(customer.agentId) : '',
        notes: customer?.notes ?? '',
        is_active: customer?.isActive ?? true,
    });

    useEffect(() => {
        if (customer) {
            setData({
                branch_id: customer.branchId ? String(customer.branchId) : '',
                full_name: customer.fullName ?? '',
                phone: customer.phone ?? '',
                email: customer.email ?? '',
                customer_type: customer.customerType.value ?? 'individual',
                company_name: customer.companyName ?? '',
                tax_number: customer.taxNumber ?? '',
                credit_limit:
                    customer.creditLimit !== null && customer.creditLimit !== undefined
                        ? String(customer.creditLimit)
                        : '',
                agent_id: customer.agentId ? String(customer.agentId) : '',
                notes: customer.notes ?? '',
                is_active: customer.isActive ?? true,
            });
        } else {
            reset();
        }
    }, [customer, open]);

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();

        if (isEdit) {
            put(update.url(customer), {
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
            <DialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'تعديل بيانات العميل' : 'إضافة عميل جديد'}</DialogTitle>
                </DialogHeader>

                <form id="customer-form" onSubmit={handleSubmit} className="space-y-4 py-2">
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        {isSuperAdmin && !isEdit && (
                            <div className="space-y-1 sm:col-span-2">
                                <Label htmlFor="cf-branch-id">
                                    الفرع <span className="text-destructive">*</span>
                                </Label>
                                <Select
                                    value={data.branch_id === '' ? 'none' : data.branch_id}
                                    onValueChange={(v) => setData('branch_id', v === 'none' ? '' : v)}
                                >
                                    <SelectTrigger id="cf-branch-id">
                                        <SelectValue placeholder="اختر الفرع" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {branches.map((branch) => (
                                            <SelectItem key={branch.id} value={String(branch.id)}>
                                                {branch.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.branch_id} />
                            </div>
                        )}

                        <div className="space-y-1">
                            <Label htmlFor="cf-full-name">
                                الاسم الكامل <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                id="cf-full-name"
                                value={data.full_name}
                                onChange={(e) => setData('full_name', e.target.value)}
                                placeholder="أدخل الاسم الكامل"
                                autoFocus
                            />
                            <InputError message={errors.full_name} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="cf-phone">
                                رقم الهاتف <span className="text-destructive">*</span>
                            </Label>
                            <Input
                                id="cf-phone"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                                placeholder="05XXXXXXXX"
                                dir="ltr"
                            />
                            <InputError message={errors.phone} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="cf-email">البريد الإلكتروني</Label>
                            <Input
                                id="cf-email"
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="example@email.com"
                                dir="ltr"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="cf-customer-type">
                                نوع العميل <span className="text-destructive">*</span>
                            </Label>
                            <Select
                                value={data.customer_type}
                                onValueChange={(v) => setData('customer_type', v as 'individual' | 'corporate')}
                            >
                                <SelectTrigger id="cf-customer-type">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="individual">فردي</SelectItem>
                                    <SelectItem value="corporate">مؤسسة</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={errors.customer_type} />
                        </div>

                        {data.customer_type === 'corporate' && (
                            <div className="space-y-1 sm:col-span-2">
                                <Label htmlFor="cf-company-name">
                                    اسم الشركة / المؤسسة <span className="text-destructive">*</span>
                                </Label>
                                <Input
                                    id="cf-company-name"
                                    value={data.company_name}
                                    onChange={(e) => setData('company_name', e.target.value)}
                                    placeholder="أدخل اسم الشركة"
                                />
                                <InputError message={errors.company_name} />
                            </div>
                        )}

                        <div className="space-y-1">
                            <Label htmlFor="cf-tax-number">الرقم الضريبي</Label>
                            <Input
                                id="cf-tax-number"
                                value={data.tax_number}
                                onChange={(e) => setData('tax_number', e.target.value)}
                                placeholder="15 رقماً (اختياري)"
                                dir="ltr"
                                inputMode="numeric"
                                maxLength={15}
                            />
                            <InputError message={errors.tax_number} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="cf-credit-limit">
                                الحد الائتماني (ر.س)
                                <span className="ms-1 text-xs text-muted-foreground">(اتركه فارغاً = نقداً فقط)</span>
                            </Label>
                            <Input
                                id="cf-credit-limit"
                                type="number"
                                min="0"
                                step="0.01"
                                value={data.credit_limit}
                                onChange={(e) => setData('credit_limit', e.target.value)}
                                placeholder="0.00"
                                dir="ltr"
                            />
                            <InputError message={errors.credit_limit} />
                        </div>

                        <div className="space-y-1">
                            <Label htmlFor="cf-agent-id">الوكيل</Label>
                            <Select
                                value={data.agent_id === '' ? 'none' : data.agent_id}
                                onValueChange={(v) => setData('agent_id', v === 'none' ? '' : v)}
                            >
                                <SelectTrigger id="cf-agent-id">
                                    <SelectValue placeholder="اختر وكيلاً (اختياري)" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">بدون وكيل</SelectItem>
                                    {agents.map((agent) => (
                                        <SelectItem key={agent.id} value={String(agent.id)}>
                                            {agent.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.agent_id} />
                        </div>

                        <div className="space-y-1 sm:col-span-2">
                            <Label htmlFor="cf-notes">ملاحظات</Label>
                            <textarea
                                id="cf-notes"
                                rows={3}
                                value={data.notes}
                                onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setData('notes', e.target.value)}
                                placeholder="أي ملاحظات إضافية..."
                                className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            <InputError message={errors.notes} />
                        </div>

                        <div className="flex items-center gap-3">
                            <Switch
                                id="cf-is-active"
                                checked={data.is_active}
                                onCheckedChange={(v) => setData('is_active', v)}
                            />
                            <Label htmlFor="cf-is-active" className="cursor-pointer">نشط</Label>
                        </div>
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
                    <Button type="submit" form="customer-form" disabled={processing}>
                        {processing && <Loader2 className="me-2 size-4 animate-spin" />}
                        {isEdit ? 'حفظ التعديلات' : 'إنشاء العميل'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
