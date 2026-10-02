import { TablePagination } from '@/components/data-table';
import DateRangeBar from '@/components/reports/date-range-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useReportFilters } from '@/hooks/use-report-filters';
import AppLayout from '@/layouts/app-layout';
import { cn, formatSar, shiftDay } from '@/lib/utils';
import finance from '@/routes/finance';
import { type BreadcrumbItem, type Paginated } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, FileText, Lock, Plus, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface Option {
    id: number;
    name: string;
}

/** تاسك 146 — جهاز واحد بمبلغٍ لكل نوع بطاقة (المفتاح = id النوع). */
type DeviceBlock = {
    networkDeviceId: number | null;
    amounts: Record<number, string>;
};

interface NetworkDeviceOption {
    id: number;
    name: string;
    number: string;
    isDefault: boolean;
}

interface SavedDevice {
    networkDeviceId: number | null;
    cardTypeId: number | null;
    paymentMethodName: string | null;
    deviceLabel: string;
    amount: number;
}

/** الصفوف المحفوظة مجمَّعةً بالجهاز؛ صفوف ما قبل تاسك 146 (بلا جهاز) خارجها. */
/**
 * تاسك 156: الجهاز يتكرّر (أكثر من موازنة له)، فلا تُجمَّع الصفوف بالجهاز. تُقرأ
 * بترتيب حفظها، وتبدأ كتلةٌ جديدة حين يتغيّر الجهاز أو يتكرّر نوع البطاقة.
 * ponytail: موازنتان متتاليتان لجهازٍ واحد ببطاقاتٍ لا تتقاطع تُعرضان كتلةً واحدة
 * (المجموع نفسه)؛ عمود block_no إن أرادوا الفصل حرفياً.
 */
function toBlocks(devices: SavedDevice[]): DeviceBlock[] {
    const blocks: DeviceBlock[] = [];
    for (const d of devices) {
        if (d.networkDeviceId == null || d.cardTypeId == null) continue;
        let block = blocks.at(-1);
        if (!block || block.networkDeviceId !== d.networkDeviceId || d.cardTypeId in block.amounts) {
            block = { networkDeviceId: d.networkDeviceId, amounts: {} };
            blocks.push(block);
        }
        block.amounts[d.cardTypeId] = String(d.amount);
    }
    return blocks;
}

interface HistoryRow {
    date: string;
    devicesTotal: number;
    /** null = غير معتمدة — الرقم الحيّ يُرى بفتح اليوم */
    systemNet: number | null;
    approvedBy: string | null;
    /** null = غير معتمدة بعد — الفرق يُحسب حيّاً عند فتح اليوم */
    difference: number | null;
    approved: boolean;
}

interface Props {
    filters: { branch: number | null; date: string; historyFrom: string; historyTo: string };
    /** تاسك 144: على المعتمد ضمن مدى السجل؛ pending = غير المعتمد (لا يدخل المجموع). */
    historyTotals: { surplus: number; shortage: number; net: number; pending: number };
    branches: Option[];
    networkDevices: NetworkDeviceOption[];
    cardTypes: Option[];
    figures: {
        systemNet: number;
        autoTotal: number;
        autoBreakdown: { name: string; total: number }[];
        /** true = مجمَّدة ساعة الاعتماد */
        frozen: boolean;
    };
    reconciliation: {
        id: number;
        devices: SavedDevice[];
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

export default function ReconciliationIndex({ filters, branches, networkDevices, cardTypes, figures, reconciliation, history, historyTotals }: Props) {
    const approved = reconciliation?.approvedAt != null;
    const legacyDevices = reconciliation?.devices.filter((d) => d.networkDeviceId == null) ?? [];
    const defaultDevice = networkDevices.find((d) => d.isDefault) ?? networkDevices[0];

    const form = useForm<{ branch: number | null; date: string; devices: DeviceBlock[]; notes: string }>({
        branch: filters.branch,
        date: filters.date,
        // مطابقة جديدة تُفتح بالجهاز الافتراضي.
        devices: reconciliation ? toBlocks(reconciliation.devices) : defaultDevice ? [{ networkDeviceId: defaultDevice.id, amounts: {} }] : [],
        notes: reconciliation?.notes ?? '',
    });

    const devicesTotal = round2(
        legacyDevices.reduce((sum, d) => sum + d.amount, 0) +
            form.data.devices.reduce((sum, b) => sum + Object.values(b.amounts).reduce((s, a) => s + (Number(a) || 0), 0), 0),
    );
    const difference = round2(devicesTotal + figures.autoTotal - figures.systemNet);
    // لا مطابقة محفوظة ولا مبالغ مُدخلة ← لا معنى لـ«مطابق».
    const isEmpty = !reconciliation && !form.isDirty;
    const status = isEmpty ? { label: 'لا توجد مطابقة بعد', className: 'bg-muted/50 text-muted-foreground' } : result(difference);
    const today = new Date().toLocaleDateString('en-CA');
    const [confirmingApprove, setConfirmingApprove] = useState(false);

    // تنقّلٌ داخلي أوقفته التعديلات غير المحفوظة — يُستأنف من النافذة.
    const [pendingUrl, setPendingUrl] = useState<string | null>(null);
    const leaving = useRef(false);

    // إغلاق التبويب/تحديثه لا يقبل إلا تنبيه المتصفح؛ التنقّل داخل التطبيق يمرّ بالنافذة.
    useEffect(() => {
        if (!form.isDirty) return;
        const warn = (e: BeforeUnloadEvent) => e.preventDefault();
        window.addEventListener('beforeunload', warn);
        const off = router.on('before', (event) => {
            // prefetch الروابط يُطلق عند مجرد المرور بالماوس — ليس مغادرة.
            if (leaving.current || event.detail.visit.prefetch || event.detail.visit.method !== 'get') return;
            event.preventDefault();
            setPendingUrl(event.detail.visit.url.href);
        });
        return () => {
            window.removeEventListener('beforeunload', warn);
            off();
        };
    }, [form.isDirty]);

    const leave = (url: string) => {
        leaving.current = true;
        setPendingUrl(null);
        router.visit(url);
    };

    const historyFilters = useReportFilters(
        finance.reconciliation.index().url,
        { branch: String(filters.branch ?? ''), date: filters.date, history_from: filters.historyFrom, history_to: filters.historyTo },
        { branch: '', date: '', history_from: '', history_to: '' },
    );

    const visit = (params: { branch?: number | null; date?: string; page?: number }) =>
        router.get(finance.reconciliation.index().url, {
            branch: params.branch ?? filters.branch ?? undefined,
            date: params.date ?? filters.date,
            history_from: filters.historyFrom,
            history_to: filters.historyTo,
            ...(params.page && { page: params.page }),
        });

    const setBlock = (index: number, patch: Partial<DeviceBlock>) =>
        form.setData('devices', form.data.devices.map((b, i) => (i === index ? { ...b, ...patch } : b)));

    const save = (onSuccess?: () => void) => {
        form.transform((data) => ({
            ...data,
            // صفٌّ لكل (جهاز × نوع) بمبلغٍ موجب؛ الفارغ والصفر لا يُحفظان.
            devices: data.devices.flatMap((b) =>
                Object.entries(b.amounts)
                    .filter(([, amount]) => Number(amount) > 0)
                    .map(([cardTypeId, amount]) => ({ network_device_id: b.networkDeviceId, card_type_id: Number(cardTypeId), amount })),
            ),
        }));
        // بعد الحفظ تصير القيم الحالية هي «المحفوظة» فلا يعود التنبيه.
        form.post(finance.reconciliation.store().url, {
            preserveScroll: true,
            onSuccess: () => {
                form.setDefaults();
                onSuccess?.();
            },
        });
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
                        <div className="flex items-center gap-1">
                            <Button variant="outline" size="icon" aria-label="اليوم السابق" onClick={() => visit({ date: shiftDay(filters.date, -1) })}>
                                <ChevronRight className="size-4" />
                            </Button>
                            <Input id="rec-date" type="date" className="w-44" value={filters.date} onChange={(e) => e.target.value && visit({ date: e.target.value })} />
                            <Button
                                variant="outline"
                                size="icon"
                                aria-label="اليوم التالي"
                                disabled={filters.date >= today}
                                onClick={() => visit({ date: shiftDay(filters.date, 1) })}
                            >
                                <ChevronLeft className="size-4" />
                            </Button>
                            <Button variant="ghost" disabled={filters.date === today} onClick={() => visit({ date: today })}>
                                اليوم
                            </Button>
                        </div>
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

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-muted-foreground text-sm font-medium">صافي المبلغ (النظام)</CardTitle>
                        </CardHeader>
                        <CardContent className="text-xl font-semibold">{formatSar(figures.systemNet)}</CardContent>
                    </Card>
                    {/* تاسك 158: محصَّل الشبكة في النظام = الصافي − التلقائي (التلقائي كل ما عدا الشبكة)،
                        فيصحّ للمجمَّد أيضاً بلا عمودٍ جديد. */}
                    <Card>
                        <CardHeader className="pb-2">
                            <CardTitle className="text-muted-foreground text-sm font-medium">الشبكة (النظام) — المطلوب مطابقته</CardTitle>
                        </CardHeader>
                        <CardContent className="text-xl font-semibold">{formatSar(round2(figures.systemNet - figures.autoTotal))}</CardContent>
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
                            {!isEmpty && difference !== 0 && ` ${formatSar(Math.abs(difference))}`}
                        </span>
                        {!isEmpty && (
                            <span className="mt-1 text-xs tabular-nums opacity-80" title="الأجهزة + الحوالات − صافي النظام">
                                <bdi>{formatSar(devicesTotal)}</bdi> + <bdi>{formatSar(figures.autoTotal)}</bdi> − <bdi>{formatSar(figures.systemNet)}</bdi>
                            </span>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle>موازنات أجهزة الشبكة</CardTitle>
                            {!approved && defaultDevice && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => form.setData('devices', [...form.data.devices, { networkDeviceId: defaultDevice.id, amounts: {} }])}
                                >
                                    <Plus className="size-4" />
                                    إضافة موازنة
                                </Button>
                            )}
                        </CardHeader>
                        <CardContent className="flex flex-col gap-3">
                            {networkDevices.length === 0 && (
                                <p className="text-muted-foreground text-sm">لا توجد أجهزة شبكة — أضفها من الإعدادات ← أجهزة الشبكة.</p>
                            )}
                            {legacyDevices.length > 0 && (
                                <div className="bg-muted/40 rounded-md border p-3 text-sm">
                                    <p className="text-muted-foreground mb-1 text-xs">إدخالات سابقة لقائمة الأجهزة (تُحتسب ولا تُعدَّل):</p>
                                    {legacyDevices.map((d, i) => (
                                        <div key={i} className="flex justify-between gap-2 tabular-nums">
                                            <span>
                                                {d.paymentMethodName} — <bdi>{d.deviceLabel}</bdi>
                                            </span>
                                            <span>{formatSar(d.amount)}</span>
                                        </div>
                                    ))}
                                </div>
                            )}
                            {form.data.devices.map((block, i) => (
                                <div key={i} className="grid gap-2 rounded-md border p-3">
                                    <div className="flex items-center gap-2">
                                        <Select
                                            disabled={approved}
                                            value={block.networkDeviceId ? String(block.networkDeviceId) : ''}
                                            onValueChange={(v) => setBlock(i, { networkDeviceId: Number(v) })}
                                        >
                                            <SelectTrigger className="flex-1">
                                                <SelectValue placeholder="الجهاز" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {networkDevices.map((d) => (
                                                    <SelectItem key={d.id} value={String(d.id)}>
                                                        {d.name} — <bdi>{d.number}</bdi>
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
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
                                    </div>
                                    <div className="grid grid-cols-[repeat(auto-fill,minmax(9rem,1fr))] gap-2">
                                        {cardTypes.map((type) => (
                                            <div key={type.id} className="grid gap-1">
                                                <Label className="text-muted-foreground text-xs">{type.name}</Label>
                                                <Input
                                                    disabled={approved}
                                                    type="number"
                                                    min="0"
                                                    step="0.01"
                                                    inputMode="decimal"
                                                    placeholder="0.00"
                                                    value={block.amounts[type.id] ?? ''}
                                                    onChange={(e) => setBlock(i, { amounts: { ...block.amounts, [type.id]: e.target.value } })}
                                                />
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ))}
                            {/* أخطاء الصفوف مفهرسةٌ على الصفوف المسطَّحة لا على الكتل — تُعرض مجمَّعة. */}
                            {[...new Set(Object.entries(form.errors).filter(([key]) => key.startsWith('devices.')).map(([, message]) => message))].map((message) => (
                                <p key={message} className="text-destructive text-xs">
                                    {message}
                                </p>
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
                                    <Button onClick={() => save()} disabled={form.processing}>
                                        حفظ المطابقة
                                    </Button>
                                )}
                                {reconciliation?.canApprove && (
                                    <Button
                                        variant="outline"
                                        disabled={form.isDirty || form.processing}
                                        title={form.isDirty ? 'احفظ التعديلات أولاً' : undefined}
                                        onClick={() => setConfirmingApprove(true)}
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
                    <CardContent className="space-y-4 overflow-x-auto">
                        <DateRangeBar filters={historyFilters} from={filters.historyFrom} to={filters.historyTo} fromKey="history_from" toKey="history_to" />
                        <div className="grid gap-3 sm:grid-cols-3">
                            {([['إجمالي الزيادة', historyTotals.surplus], ['إجمالي العجز', historyTotals.shortage]] as const).map(([label, value]) => (
                                <div key={label} className="rounded-lg border p-3">
                                    <p className="text-muted-foreground text-xs">{label}</p>
                                    <p className="text-lg font-semibold tabular-nums">{formatSar(value)}</p>
                                </div>
                            ))}
                            <div className={cn('rounded-lg border p-3', result(historyTotals.net).className)}>
                                <p className="text-xs">الفرق</p>
                                <p className="text-lg font-semibold tabular-nums">
                                    {formatSar(Math.abs(historyTotals.net))} {result(historyTotals.net).label}
                                </p>
                            </div>
                        </div>
                        {historyTotals.pending > 0 && (
                            <p className="text-muted-foreground text-xs">{historyTotals.pending} مطابقة غير معتمدة في الفترة لا تدخل في المجموع.</p>
                        )}
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground border-b text-start">
                                <tr>
                                    <th className="py-2 text-start font-medium">التاريخ</th>
                                    <th className="py-2 text-start font-medium">صافي النظام</th>
                                    <th className="py-2 text-start font-medium">موازنات الأجهزة</th>
                                    <th className="py-2 text-start font-medium">النتيجة</th>
                                    <th className="py-2 text-start font-medium">المعتمِد</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {history.data.map((row) => (
                                    <tr
                                        key={row.date}
                                        aria-current={row.date === filters.date ? 'date' : undefined}
                                        className={cn('hover:bg-muted/50 cursor-pointer', row.date === filters.date && 'bg-muted font-medium')}
                                        onClick={() => visit({ date: row.date })}
                                    >
                                        <td className="py-2">{row.date.split('-').reverse().join('/')}</td>
                                        <td className="py-2 tabular-nums">{row.systemNet === null ? '—' : formatSar(row.systemNet)}</td>
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
                                        <td className="py-2">{row.approvedBy ?? '—'}</td>
                                    </tr>
                                ))}
                                {history.data.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="text-muted-foreground py-6 text-center">
                                            لا مطابقات محفوظة في هذه الفترة.
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

            <Dialog open={confirmingApprove} onOpenChange={setConfirmingApprove}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>اعتماد مطابقة {filters.date.split('-').reverse().join('/')}</DialogTitle>
                        <DialogDescription>بعد الاعتماد تُجمَّد الأرقام ولا يمكن تعديل الأجهزة إلا بإلغاء الاعتماد.</DialogDescription>
                    </DialogHeader>
                    <div className={cn('rounded-lg border p-3 text-sm font-semibold', status.className)}>
                        النتيجة: {status.label}
                        {difference !== 0 && ` ${formatSar(Math.abs(difference))}`}
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirmingApprove(false)}>
                            إلغاء
                        </Button>
                        <Button
                            onClick={() =>
                                reconciliation &&
                                router.post(finance.reconciliation.approve(reconciliation.id).url, {}, { preserveScroll: true, onFinish: () => setConfirmingApprove(false) })
                            }
                        >
                            تأكيد الاعتماد
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={pendingUrl !== null} onOpenChange={(open) => !open && setPendingUrl(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تعديلات غير محفوظة</DialogTitle>
                        <DialogDescription>لديك تعديلات على مطابقة هذا اليوم لم تُحفظ بعد. ماذا تريد أن تفعل؟</DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={() => setPendingUrl(null)}>
                            البقاء
                        </Button>
                        <Button variant="destructive" onClick={() => pendingUrl && leave(pendingUrl)}>
                            مغادرة دون حفظ
                        </Button>
                        <Button
                            disabled={form.processing}
                            onClick={() => {
                                const url = pendingUrl;
                                // فشل الحفظ (أخطاء تحقق) ← تُغلق النافذة ويبقى في الصفحة ليرى الأخطاء.
                                setPendingUrl(null);
                                if (url) save(() => leave(url));
                            }}
                        >
                            حفظ ثم مغادرة
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
