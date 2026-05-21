import {
    destroy,
    toggleStatus,
} from '@/actions/App/Http/Controllers/PaymentMethodController';
import {
    updateGeneral,
    updateInventoryAlerts,
} from '@/actions/App/Http/Controllers/AppSettingController';
import PaymentMethodFormModal from '@/components/app-settings/payment-method-form-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import {
    type AppSettingsGeneralData,
    type AppSettingsInventoryData,
    type PaymentMethod,
} from '@/types/payment-method';
import { router, useForm } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'الإعدادات', href: '/app-settings' },
];

interface Props {
    generalSettings: AppSettingsGeneralData;
    inventoryAlerts: AppSettingsInventoryData;
    paymentMethods: PaymentMethod[];
    isSuperAdmin: boolean;
}

export default function AppSettingsIndex({
    generalSettings,
    inventoryAlerts,
    paymentMethods,
    isSuperAdmin,
}: Props) {
    const [pmFormOpen, setPmFormOpen] = useState(false);
    const [editing, setEditing] = useState<PaymentMethod | null>(null);
    const [deleting, setDeleting] = useState<PaymentMethod | null>(null);

    const generalForm = useForm({
        app_name: generalSettings.appName ?? '',
        default_vat_pct: generalSettings.defaultVatPct ?? '15.00',
        vat_override_pct: generalSettings.vatOverridePct?.toString() ?? '',
    });

    const inventoryForm = useForm({
        min_stock_alert_threshold: inventoryAlerts.minStockAlertThreshold ?? '10',
    });

    function openCreate() {
        setEditing(null);
        setPmFormOpen(true);
    }

    function openEdit(pm: PaymentMethod) {
        setEditing(pm);
        setPmFormOpen(true);
    }

    function handleToggle(pm: PaymentMethod) {
        router.patch(toggleStatus.url(pm), {}, { preserveScroll: true });
    }

    function handleDelete() {
        if (!deleting) return;
        router.delete(destroy.url(deleting), {
            preserveScroll: true,
            onFinish: () => setDeleting(null),
        });
    }

    function submitGeneral(e: React.FormEvent) {
        e.preventDefault();
        generalForm.put(updateGeneral.url(), { preserveScroll: true });
    }

    function submitInventory(e: React.FormEvent) {
        e.preventDefault();
        inventoryForm.put(updateInventoryAlerts.url(), { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold">الإعدادات</h1>
                </div>

                <Tabs defaultValue="general" dir="rtl">
                    <TabsList className="mb-6 w-full justify-start">
                        <TabsTrigger value="general">عام</TabsTrigger>
                        <TabsTrigger value="payment-methods">طرق الدفع</TabsTrigger>
                        <TabsTrigger value="loyalty">برنامج الولاء</TabsTrigger>
                        <TabsTrigger value="inventory-alerts">تنبيهات المخزون</TabsTrigger>
                    </TabsList>

                    {/* ── General ─────────────────────────────────────── */}
                    <TabsContent value="general">
                        <div className="rounded-lg border p-6">
                            <h2 className="mb-4 text-lg font-semibold">الإعدادات العامة</h2>
                            <form onSubmit={submitGeneral} className="space-y-4">
                                {isSuperAdmin && (
                                    <>
                                        <div className="space-y-1">
                                            <Label htmlFor="app-name">اسم التطبيق</Label>
                                            <Input
                                                id="app-name"
                                                value={generalForm.data.app_name}
                                                onChange={(e) => generalForm.setData('app_name', e.target.value)}
                                            />
                                            <InputError message={generalForm.errors.app_name} />
                                        </div>

                                        <div className="space-y-1">
                                            <Label htmlFor="default-vat">نسبة ضريبة القيمة المضافة الافتراضية (%)</Label>
                                            <Input
                                                id="default-vat"
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                max="100"
                                                value={generalForm.data.default_vat_pct}
                                                onChange={(e) => generalForm.setData('default_vat_pct', e.target.value)}
                                            />
                                            <p className="text-muted-foreground text-xs">
                                                التغييرات لا تُطبَّق بأثر رجعي على الفواتير السابقة.
                                            </p>
                                            <InputError message={generalForm.errors.default_vat_pct} />
                                        </div>
                                    </>
                                )}

                                {!isSuperAdmin && (
                                    <div className="space-y-1">
                                        <Label htmlFor="vat-override">نسبة ضريبة القيمة المضافة للفرع (%)</Label>
                                        <Input
                                            id="vat-override"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            max="100"
                                            value={generalForm.data.vat_override_pct}
                                            onChange={(e) => generalForm.setData('vat_override_pct', e.target.value)}
                                        />
                                        <p className="text-muted-foreground text-xs">
                                            التغييرات لا تُطبَّق بأثر رجعي على الفواتير السابقة.
                                        </p>
                                        <InputError message={generalForm.errors.vat_override_pct} />
                                    </div>
                                )}

                                <Button type="submit" disabled={generalForm.processing}>
                                    {generalForm.processing ? 'جاري الحفظ...' : 'حفظ الإعدادات'}
                                </Button>
                            </form>
                        </div>
                    </TabsContent>

                    {/* ── Payment Methods ──────────────────────────────── */}
                    <TabsContent value="payment-methods">
                        <div className="rounded-lg border">
                            <div className="flex items-center justify-between border-b p-4">
                                <h2 className="text-lg font-semibold">طرق الدفع</h2>
                                <Button size="sm" onClick={openCreate}>
                                    <Plus className="size-4" /> إضافة طريقة دفع
                                </Button>
                            </div>

                            {paymentMethods.length === 0 ? (
                                <p className="text-muted-foreground p-6 text-center text-sm">
                                    لا توجد طرق دفع مضافة بعد.
                                </p>
                            ) : (
                                <ul className="divide-y">
                                    {paymentMethods.map((pm) => (
                                        <li key={pm.id} className="flex items-center justify-between px-4 py-3">
                                            <div className="flex items-center gap-3">
                                                <span className="font-medium">{pm.name}</span>
                                                <button
                                                    onClick={() => handleToggle(pm)}
                                                    className="cursor-pointer"
                                                >
                                                    {pm.isActive ? (
                                                        <Badge variant="outline" className="gap-1.5 border-green-200 bg-green-50 text-green-700">
                                                            <span className="inline-block size-1.5 rounded-full bg-green-500" />
                                                            نشطة
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="outline" className="gap-1.5 border-border bg-muted/60 text-muted-foreground">
                                                            <span className="inline-block size-1.5 rounded-full bg-muted-foreground/50" />
                                                            غير نشطة
                                                        </Badge>
                                                    )}
                                                </button>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => openEdit(pm)}
                                                >
                                                    <Pencil className="h-3.5 w-3.5" />
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="text-destructive hover:text-destructive"
                                                    onClick={() => setDeleting(pm)}
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                </Button>
                                            </div>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </TabsContent>

                    {/* ── Loyalty Program ──────────────────────────────── */}
                    <TabsContent value="loyalty">
                        <div className="rounded-lg border p-6">
                            <h2 className="mb-2 text-lg font-semibold">برنامج الولاء</h2>
                            <p className="text-muted-foreground text-sm">
                                إعدادات برنامج الولاء متاحة في وحدة M28 — نظام الولاء.
                            </p>
                        </div>
                    </TabsContent>

                    {/* ── Inventory Alerts ─────────────────────────────── */}
                    <TabsContent value="inventory-alerts">
                        <div className="rounded-lg border p-6">
                            <h2 className="mb-4 text-lg font-semibold">تنبيهات المخزون</h2>
                            <form onSubmit={submitInventory} className="space-y-4">
                                <div className="space-y-1">
                                    <Label htmlFor="min-stock">الحد الأدنى للمخزون (تنبيه)</Label>
                                    <Input
                                        id="min-stock"
                                        type="number"
                                        min="0"
                                        value={inventoryForm.data.min_stock_alert_threshold}
                                        onChange={(e) => inventoryForm.setData('min_stock_alert_threshold', e.target.value)}
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        يظهر تنبيه عندما يصل المخزون إلى هذه الكمية أو أقل.
                                    </p>
                                    <InputError message={inventoryForm.errors.min_stock_alert_threshold} />
                                </div>

                                <Button type="submit" disabled={inventoryForm.processing}>
                                    {inventoryForm.processing ? 'جاري الحفظ...' : 'حفظ الإعدادات'}
                                </Button>
                            </form>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>

            {/* Delete confirmation */}
            <Dialog open={!!deleting} onOpenChange={(open) => !open && setDeleting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>تأكيد الحذف</DialogTitle>
                        <DialogDescription>
                            هل أنت متأكد من حذف طريقة الدفع "{deleting?.name}"؟ لا يمكن حذفها إذا كانت مرتبطة بفواتير.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeleting(null)}>
                            إلغاء
                        </Button>
                        <Button variant="destructive" onClick={handleDelete}>
                            حذف
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <PaymentMethodFormModal
                key={editing?.id ?? 'create'}
                open={pmFormOpen}
                onOpenChange={setPmFormOpen}
                paymentMethod={editing ?? undefined}
            />
        </AppLayout>
    );
}
