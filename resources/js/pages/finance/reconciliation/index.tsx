import { TablePagination } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn, formatSar } from '@/lib/utils';
import finance from '@/routes/finance';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FileText, Lock, Plus, Trash2 } from 'lucide-react';

interface Option {
    id: number;
    name: string;
}

type Device = {
    paymentMethodId: number | null;
    deviceLabel: string;
    amount: string;
};

interface HistoryRow {
    date: string;
    devicesTotal: number;
    /** null = غير معتمدة بعد — الفرق يُحسب حيّاً عند فتح اليوم */
    difference: number | null;
    approved: boolean;
}

interface Props {
    filters: { branch: number | null; date: string };
    branches: Option[];
    networkMethods: Option[];
    figures: {
        systemNet: number;
        autoTotal: number;
        autoBreakdown: { name: string; total: number }[];
        /** true = مجمَّدة ساعة الاعتماد */
        frozen: boolean;
    };
    reconciliation: {
        id: number;
        devices: { paymentMethodId: number; deviceLabel: string; amount: number }[];
        notes: string | null;
        createdBy: string | null;
        approvedBy: string | null;
        approvedAt: string | null;
        canApprove: boolean;
        canUnapprove: boolean;
        settlementFileUrl: string | null;
    } | null;
    history: Paginated<HistoryRow>;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'مطابقة الحسابات', href: finance.reconciliation.index().url }];

const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;

/** تاسك 121 — مساوٍ مطابق، أقل عجز، أكبر زيادة. */
function result(difference: number) {
    if (difference === 0) return { label: 'مطابق', className: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300' };
    if (difference < 0) return { label: 'عجز', className: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300' };
    return { label: 'زيادة', className: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-300' };
}

export default function ReconciliationIndex({ filters, branches, networkMethods, figures, reconciliation, history }: Props) {
    const approved = reconciliation?.approvedAt != null;

    const form = useForm<{ branch: number | null; date: string; devices: Device[]; notes: string }>({
        branch: filters.branch,
        date: filters.date,
        devices: reconciliation?.devices.map((d) => ({ ...d, amount: String(d.amount) })) ?? [],
        notes: reconciliation?.notes ?? '',
    });

    const devicesTotal = round2(form.data.devices.reduce((sum, d) => sum + (Number(d.amount) || 0), 0));
    const difference = round2(devicesTotal + figures.autoTotal - figures.systemNet);
    const status = result(difference);

    const visit = (params: { branch?: number | null; date?: string; page?: number }) =>
        router.get(finance.reconciliation.index().url, {
            branch: params.branch ?? filters.branch ?? undefined,
            date: params.date ?? filters.date,
            ...(params.page && { page: params.page }),
        });

    const setDevice = (index: number, patch: Partial<Device>) =>
        form.setData('devices', form.data.devices.map((d, i) => (i === index ? { ...d, ...patch } : d)));

    const save = () => {
        form.transform((data) => ({
            ...data,
            devices: data.devices.map((d) => ({ payment_method_id: d.paymentMethodId, device_label: d.deviceLabel, amount: d.amount })),
        }));
        form.post(finance.reconciliation.store().url, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="مطابقة الحسابات" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-end gap-3">
                    {branches.length > 0 && (
                        <div className="grid gap-1.5">
                            <Label>الفرع</Label>
                            <Select value={String(filters.branch ?? '')} onValueChange={(v) => visit({ branch: Number(v) })}>
                                <SelectTrigger className="w-48">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {branches.map((b) => (
                                        <SelectItem key={b.id} value={String(b.id)}>
                                            {b.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}
                    <div className="grid gap-1.5">
                        <Label htmlFor="rec-date">التاريخ</Label>
                        <Input id="rec-date" type="date" className="w-44" value={filters.date} onChange={(e) => e.target.value && visit({ date: e.target.value })} />
                    </div>
                    {reconciliation?.settlementFileUrl && (
                        <Button variant="outline" asChild>
                            <a href={reconciliation.settlementFileUrl} target="_blank" rel="noreferrer">
                                <FileText className="size-4" />
                                ملف موازنة الشبكة
                            </a>
                        </Button>
                    )}
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-muted-foreground text-sm font-medium">صافي المبلغ (النظام)</CardTitle>
                        </CardHeader>
                        <CardContent className="text-xl font-semibold">{formatSar(figures.systemNet)}</CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-muted-foreground text-sm font-medium">موازنات أجهزة الشبكة</CardTitle>
                        </CardHeader>
                        <CardContent className="text-xl font-semibold">{formatSar(devicesTotal)}</CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-muted-foreground text-sm font-medium">الحوالات ووسائل الدفع الأخرى</CardTitle>
                        </CardHeader>
                        <CardContent className="text-xl font-semibold">{formatSar(figures.autoTotal)}</CardContent>
                    </Card>
                    <div className={cn('flex flex-col justify-center rounded-xl border p-4', status.className)}>
                        <span className="text-sm font-medium">النتيجة</span>
                        <span className="text-xl font-semibold">
                            {status.label}
                            {difference !== 0 && ` ${formatSar(Math.abs(difference))}`}
                        </span>
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>موازنات أجهزة الشبكة</CardTitle>
                            {!approved && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => form.setData('devices', [...form.data.devices, { paymentMethodId: networkMethods[0]?.id ?? null, deviceLabel: '', amount: '' }])}
                                >
                                    <Plus className="size-4" />
                                    إضافة جهاز
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {networkMethods.length === 0 && (
                                <p className="text-muted-foreground text-sm">لا توجد طرق دفع معلَّمة «شبكة» — فعّلها من الإعدادات ← طرق الدفع.</p>
                            )}
                            {form.data.devices.map((device, i) => (
                                <div key={i} className="grid grid-cols-[1fr_1fr_8rem_auto] items-start gap-2">
                                    <Select
                                        disabled={approved}
                                        value={device.paymentMethodId ? String(device.paymentMethodId) : ''}
                                        onValueChange={(v) => setDevice(i, { paymentMethodId: Number(v) })}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder="الطريقة" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {networkMethods.map((m) => (
                                                <SelectItem key={m.id} value={String(m.id)}>
                                                    {m.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Input disabled={approved} placeholder="رقم الجهاز" value={device.deviceLabel} onChange={(e) => setDevice(i, { deviceLabel: e.target.value })} />
                                    <Input
                                        disabled={approved}
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        inputMode="decimal"
                                        placeholder="المبلغ"
                                        value={device.amount}
                                        onChange={(e) => setDevice(i, { amount: e.target.value })}
                                    />
                                    {!approved && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            aria-label="حذف الجهاز"
                                            onClick={() => form.setData('devices', form.data.devices.filter((_, j) => j !== i))}
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    )}
                                    {Object.entries(form.errors)
                                        .filter(([key]) => key.startsWith(`devices.${i}.`))
                                        .map(([key, message]) => (
                                            <p key={key} className="text-destructive col-span-full text-xs">
                                                {message}
                                            </p>
                                        ))}
                                </div>
                            ))}
                            {form.errors.devices && <p className="text-destructive text-sm">{form.errors.devices}</p>}

                            <div className="grid gap-1.5">
                                <Label htmlFor="rec-notes">ملاحظات</Label>
                                <textarea
                                    id="rec-notes"
                                    disabled={approved}
                                    rows={2}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                    value={form.data.notes}
                                    onChange={(e) => form.setData('notes', e.target.value)}
                                />
                            </div>

                            <div className="flex flex-wrap items-center gap-2">
                                {!approved && (
                                    <Button onClick={save} disabled={form.processing}>
                                        حفظ المطابقة
                                    </Button>
                                )}
                                {reconciliation?.canApprove && (
                                    <Button
                                        variant="outline"
                                        disabled={form.isDirty || form.processing}
                                        title={form.isDirty ? 'احفظ التعديلات أولاً' : undefined}
                                        onClick={() => router.post(finance.reconciliation.approve(reconciliation.id).url, {}, { preserveScroll: true })}
                                    >
                                        اعتماد
                                    </Button>
                                )}
                                {reconciliation?.canUnapprove && (
                                    <Button variant="outline" onClick={() => router.post(finance.reconciliation.unapprove(reconciliation.id).url, {}, { preserveScroll: true })}>
                                        إلغاء الاعتماد
                                    </Button>
                                )}
                                {approved && (
                                    <span className="text-muted-foreground flex items-center gap-1 text-sm">
                                        <Lock className="size-3.5" />
                                        معتمدة بواسطة {reconciliation?.approvedBy} — الأرقام مجمَّدة
                                    </span>
                                )}
                                {reconciliation?.createdBy && !approved && (
                                    <span className="text-muted-foreground text-sm">أنشأها: {reconciliation.createdBy}</span>
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>المسحوب تلقائياً</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {figures.autoBreakdown.length === 0 ? (
                                <p className="text-muted-foreground text-sm">لا تحصيل بغير الشبكة في هذا اليوم.</p>
                            ) : (
                                <ul className="divide-y text-sm">
                                    {figures.autoBreakdown.map((line) => (
                                        <li key={line.name} className="flex justify-between py-1.5">
                                            <span>{line.name}</span>
                                            <span className="tabular-nums">{formatSar(line.total)}</span>
                                        </li>
                                    ))}
                                    <li className="flex justify-between py-1.5 font-semibold">
                                        <span>الإجمالي</span>
                                        <span className="tabular-nums">{formatSar(figures.autoTotal)}</span>
                                    </li>
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>سجل المطابقات</CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground border-b text-start">
                                <tr>
                                    <th className="py-2 text-start font-medium">التاريخ</th>
                                    <th className="py-2 text-start font-medium">موازنات الأجهزة</th>
                                    <th className="py-2 text-start font-medium">النتيجة</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {history.data.map((row) => (
                                    <tr key={row.date} className="hover:bg-muted/50 cursor-pointer" onClick={() => visit({ date: row.date })}>
                                        <td className="py-2">{row.date.split('-').reverse().join('/')}</td>
                                        <td className="py-2 tabular-nums">{formatSar(row.devicesTotal)}</td>
                                        <td className="py-2">
                                            {row.difference === null ? (
                                                <Badge variant="outline">بانتظار الاعتماد</Badge>
                                            ) : (
                                                <Badge variant="outline" className={result(row.difference).className}>
                                                    {result(row.difference).label}
                                                    {row.difference !== 0 && ` ${formatSar(Math.abs(row.difference))}`}
                                                </Badge>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {history.data.length === 0 && (
                                    <tr>
                                        <td colSpan={3} className="text-muted-foreground py-6 text-center">
                                            لا مطابقات محفوظة لهذا الفرع.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                        {history.meta.last_page > 1 && (
                            <TablePagination
                                currentPage={history.meta.current_page}
                                totalPages={history.meta.last_page}
                                totalItems={history.meta.total}
                                from={history.meta.from}
                                to={history.meta.to}
                                onPageChange={(page) => visit({ page })}
                            />
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
