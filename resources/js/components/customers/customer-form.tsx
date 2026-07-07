import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { type Customer } from '@/types/customer';
import { useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';

interface Props {
    customer?: Customer;
    submitUrl: string;
    method: 'post' | 'put';
    submitLabel: string;
    cancelHref: string;
}

export default function CustomerForm({ customer, submitUrl, method, submitLabel, cancelHref }: Props) {
    const { data, setData, post, put, processing, errors } = useForm({
        full_name: customer?.fullName ?? '',
        phone: customer?.phone ?? '',
        email: customer?.email ?? '',
        customer_type: customer?.customerType.value ?? 'individual',
        company_name: customer?.companyName ?? '',
        tax_number: customer?.taxNumber ?? '',
        credit_limit: customer?.creditLimit !== null && customer?.creditLimit !== undefined
            ? String(customer.creditLimit)
            : '',
        agent_id: customer?.agentId ? String(customer.agentId) : '',
        notes: customer?.notes ?? '',
        is_active: customer?.isActive ?? true,
    });

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        if (method === 'post') {
            post(submitUrl);
        } else {
            put(submitUrl);
        }
    }

    return (
        <form onSubmit={handleSubmit} className="space-y-6">
            <div className="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <div className="space-y-2">
                    <Label htmlFor="full_name">
                        الاسم الكامل <span className="text-destructive">*</span>
                    </Label>
                    <Input
                        id="full_name"
                        value={data.full_name}
                        onChange={(e) => setData('full_name', e.target.value)}
                        placeholder="أدخل الاسم الكامل"
                    />
                    {errors.full_name && (
                        <p className="text-sm text-destructive">{errors.full_name}</p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="phone">
                        رقم الهاتف <span className="text-destructive">*</span>
                    </Label>
                    <Input
                        id="phone"
                        value={data.phone}
                        onChange={(e) => setData('phone', e.target.value)}
                        placeholder="05XXXXXXXX"
                        dir="ltr"
                    />
                    {errors.phone && (
                        <p className="text-sm text-destructive">{errors.phone}</p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="email">البريد الإلكتروني</Label>
                    <Input
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        placeholder="example@email.com"
                        dir="ltr"
                    />
                    {errors.email && (
                        <p className="text-sm text-destructive">{errors.email}</p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="customer_type">
                        نوع العميل <span className="text-destructive">*</span>
                    </Label>
                    <Select
                        value={data.customer_type}
                        onValueChange={(v) => setData('customer_type', v as 'individual' | 'corporate')}
                    >
                        <SelectTrigger id="customer_type">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="individual">فردي</SelectItem>
                            <SelectItem value="corporate">مؤسسة</SelectItem>
                        </SelectContent>
                    </Select>
                    {errors.customer_type && (
                        <p className="text-sm text-destructive">{errors.customer_type}</p>
                    )}
                </div>

                {data.customer_type === 'corporate' && (
                    <div className="space-y-2 sm:col-span-2">
                        <Label htmlFor="company_name">
                            اسم الشركة / المؤسسة <span className="text-destructive">*</span>
                        </Label>
                        <Input
                            id="company_name"
                            value={data.company_name}
                            onChange={(e) => setData('company_name', e.target.value)}
                            placeholder="أدخل اسم الشركة"
                        />
                        {errors.company_name && (
                            <p className="text-sm text-destructive">{errors.company_name}</p>
                        )}
                    </div>
                )}

                <div className="space-y-2">
                    <Label htmlFor="tax_number">الرقم الضريبي</Label>
                    <Input
                        id="tax_number"
                        value={data.tax_number}
                        onChange={(e) => setData('tax_number', e.target.value)}
                        placeholder="15 رقماً (اختياري)"
                        dir="ltr"
                        inputMode="numeric"
                        maxLength={15}
                    />
                    {errors.tax_number && (
                        <p className="text-sm text-destructive">{errors.tax_number}</p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="credit_limit">
                        الحد الائتماني (ر.س)
                        <span className="ms-1 text-xs text-muted-foreground">(اتركه فارغاً = نقداً فقط)</span>
                    </Label>
                    <Input
                        id="credit_limit"
                        type="number"
                        min="0"
                        step="0.01"
                        value={data.credit_limit}
                        onChange={(e) => setData('credit_limit', e.target.value)}
                        placeholder="0.00"
                        dir="ltr"
                    />
                    {errors.credit_limit && (
                        <p className="text-sm text-destructive">{errors.credit_limit}</p>
                    )}
                </div>

                <div className="space-y-2">
                    <Label htmlFor="agent_id">الوكيل</Label>
                    <Input
                        id="agent_id"
                        type="number"
                        min="1"
                        value={data.agent_id}
                        onChange={(e) => setData('agent_id', e.target.value)}
                        placeholder="معرّف الوكيل (اختياري)"
                        dir="ltr"
                    />
                    {errors.agent_id && (
                        <p className="text-sm text-destructive">{errors.agent_id}</p>
                    )}
                </div>

                <div className="space-y-2 sm:col-span-2">
                    <Label htmlFor="notes">ملاحظات</Label>
                    <textarea
                        id="notes"
                        rows={3}
                        value={data.notes}
                        onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setData('notes', e.target.value)}
                        placeholder="أي ملاحظات إضافية..."
                        className="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                    />
                    {errors.notes && (
                        <p className="text-sm text-destructive">{errors.notes}</p>
                    )}
                </div>

                <div className="flex items-center gap-3">
                    <Switch
                        id="is_active"
                        checked={data.is_active}
                        onCheckedChange={(v) => setData('is_active', v)}
                    />
                    <Label htmlFor="is_active" className="cursor-pointer">
                        نشط
                    </Label>
                </div>
            </div>

            <div className="flex items-center justify-end gap-3 border-t pt-4">
                <Button variant="outline" type="button" asChild>
                    <a href={cancelHref}>إلغاء</a>
                </Button>
                <Button type="submit" disabled={processing}>
                    {processing && <Loader2 className="me-2 size-4 animate-spin" />}
                    {submitLabel}
                </Button>
            </div>
        </form>
    );
}
