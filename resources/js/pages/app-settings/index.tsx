import {
    destroy,
    toggleStatus,
} from '@/actions/App/Http/Controllers/PaymentMethodController';
import {
    updateGeneral,
    updateInventoryAlerts,
    updateLoyalty,
    updatePaymentMethods,
} from '@/actions/App/Http/Controllers/AppSettingController';
import BranchProfileTab from '@/components/app-settings/branch-profile-tab';
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
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type Branch } from '@/types/branch';
import { type City } from '@/types/city';
import {
    type AppSettingsGeneralData,
    type AppSettingsInventoryData,
    type AppSettingsLoyaltyData,
    type PaymentMethod,
} from '@/types/payment-method';
import { router, useForm } from '@inertiajs/react';
import { CreditCard, Paperclip, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';

const VALID_TABS = ['general', 'branch-profile', 'payment-methods', 'loyalty', 'inventory-alerts'] as const;
type TabValue = (typeof VALID_TABS)[number];

function getInitialTab(isSuperAdmin: boolean, hasBranchProfile: boolean): TabValue {
    // `general` holds global-only settings and `branch-profile` needs an owned
    // branch, so neither is a safe landing tab for every role.
    const fallback: TabValue = isSuperAdmin
        ? 'general'
        : hasBranchProfile
          ? 'branch-profile'
          : 'payment-methods';

    if (typeof window !== 'undefined') {
        const param = new URLSearchParams(window.location.search).get('tab') as TabValue;
        if (param === 'general' && !isSuperAdmin) return fallback;
        if (param === 'branch-profile' && !hasBranchProfile) return fallback;
        if (VALID_TABS.includes(param)) return param;
    }

    return fallback;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'الإعدادات', href: '/app-settings' },
];

interface Props {
    generalSettings: AppSettingsGeneralData;
    /** Non-null only for the manager of that branch — super-admins use /branches. */
    branchProfile: Branch | null;
    cities: City[];
    inventoryAlerts: AppSettingsInventoryData;
    paymentMethods: PaymentMethod[];
    enabledPaymentMethodIds: number[];
    isSuperAdmin: boolean;
    loyaltyConfig: AppSettingsLoyaltyData | null;
    canConfigureLoyalty: boolean;
}

export default function AppSettingsIndex({
    generalSettings,
    branchProfile,
    cities,
    inventoryAlerts,
    paymentMethods,
    enabledPaymentMethodIds,
    isSuperAdmin,
    loyaltyConfig,
    canConfigureLoyalty,
}: Props) {
    const [activeTab, setActiveTab] = useState<TabValue>(() => getInitialTab(isSuperAdmin, !!branchProfile));
    const [pmFormOpen, setPmFormOpen] = useState(false);
    const [editing, setEditing] = useState<PaymentMethod | null>(null);
    const [deleting, setDeleting] = useState<PaymentMethod | null>(null);

    const defaultEnabledIds = enabledPaymentMethodIds.length > 0
        ? enabledPaymentMethodIds
        : paymentMethods.filter((pm) => pm.isActive).map((pm) => pm.id);

    const [branchEnabledIds, setBranchEnabledIds] = useState<number[]>(defaultEnabledIds);

    const generalForm = useForm({
        app_name: generalSettings.appName ?? '',
        default_vat_pct: generalSettings.defaultVatPct ?? '15.00',
    });

    const inventoryForm = useForm({
        min_stock_alert_threshold: inventoryAlerts.minStockAlertThreshold ?? '10',
    });

    const loyaltyForm = useForm({
        is_active: loyaltyConfig?.isActive ?? true,
        earning_rate: loyaltyConfig?.earningRate?.toString() ?? '1',
        redemption_rate: loyaltyConfig?.redemptionRate?.toString() ?? '100',
        min_redemption_points: loyaltyConfig?.minRedemptionPoints?.toString() ?? '500',
        expiry_months: loyaltyConfig?.expiryMonths?.toString() ?? '',
        bronze_threshold: loyaltyConfig?.bronzeThreshold?.toString() ?? '500',
        silver_threshold: loyaltyConfig?.silverThreshold?.toString() ?? '2000',
        gold_threshold: loyaltyConfig?.goldThreshold?.toString() ?? '5000',
        bronze_discount_pct: loyaltyConfig?.bronzeDiscountPct?.toString() ?? '2',
        silver_discount_pct: loyaltyConfig?.silverDiscountPct?.toString() ?? '5',
        gold_discount_pct: loyaltyConfig?.goldDiscountPct?.toString() ?? '8',
    });

    function openCreate() {
        setEditing(null);
        setPmFormOpen(true);
    }

    function openEdit(pm: PaymentMethod) {
        setEditing(pm);
        setPmFormOpen(true);
    }

    function handleAdminToggle(pm: PaymentMethod) {
        router.patch(toggleStatus.url(pm), {}, { preserveScroll: true });
    }

    function handleDelete() {
        if (!deleting) return;
        router.delete(destroy.url(deleting), {
            preserveScroll: true,
            onFinish: () => setDeleting(null),
        });
    }

    function handleBranchToggle(pmId: number, enabled: boolean) {
        const next = enabled
            ? [...branchEnabledIds, pmId]
            : branchEnabledIds.filter((id) => id !== pmId);
        setBranchEnabledIds(next);
        router.put(updatePaymentMethods.url(), { enabled_ids: next }, { preserveScroll: true });
    }

    function submitGeneral(e: React.FormEvent) {
        e.preventDefault();
        generalForm.put(updateGeneral.url(), { preserveScroll: true });
    }

    function handleTabChange(value: string) {
        setActiveTab(value as TabValue);
        const url = new URL(window.location.href);
        url.searchParams.set('tab', value);
        window.history.replaceState({}, '', url.toString());
    }

    function submitInventory(e: React.FormEvent) {
        e.preventDefault();
        inventoryForm.put(updateInventoryAlerts.url(), { preserveScroll: true });
    }

    function submitLoyalty(e: React.FormEvent) {
        e.preventDefault();
        loyaltyForm.put(updateLoyalty.url(), { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="p-6">
                <div className="mb-6">
                    <h1 className="text-2xl font-bold">الإعدادات</h1>
                </div>

                <Tabs value={activeTab} onValueChange={handleTabChange} dir="rtl">
                    <TabsList className="mb-6 w-full justify-start">
                        {isSuperAdmin && <TabsTrigger value="general">عام</TabsTrigger>}
                        {branchProfile && <TabsTrigger value="branch-profile">بيانات الفرع</TabsTrigger>}
                        <TabsTrigger value="payment-methods">طرق الدفع</TabsTrigger>
                        <TabsTrigger value="loyalty">برنامج الولاء</TabsTrigger>
                        <TabsTrigger value="inventory-alerts">تنبيهات المخزون</TabsTrigger>
                    </TabsList>

                    {/* ── General (global settings, super-admin only) ──── */}
                    {isSuperAdmin && (
                        <TabsContent value="general">
                            <div className="rounded-lg border p-6">
                                <h2 className="mb-4 text-lg font-semibold">الإعدادات العامة</h2>
                                <form onSubmit={submitGeneral} className="space-y-4">
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

                                    <Button type="submit" disabled={generalForm.processing}>
                                        {generalForm.processing ? 'جاري الحفظ...' : 'حفظ الإعدادات'}
                                    </Button>
                                </form>
                            </div>
                        </TabsContent>
                    )}

                    {/* ── Branch data (the branch's own manager) ───────── */}
                    {branchProfile && (
                        <TabsContent value="branch-profile">
                            <BranchProfileTab branch={branchProfile} cities={cities} />
                        </TabsContent>
                    )}

                    {/* ── Payment Methods ──────────────────────────────── */}
                    <TabsContent value="payment-methods">
                        {isSuperAdmin ? (
                            /* Super-admin: full CRUD list */
                            <div className="rounded-lg border">
                                <div className="flex items-center justify-between border-b p-4">
                                    <div>
                                        <h2 className="text-lg font-semibold">طرق الدفع</h2>
                                        <p className="text-muted-foreground text-sm">إدارة القائمة العامة لطرق الدفع المتاحة لجميع الفروع.</p>
                                    </div>
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
                                                    <CreditCard className="text-muted-foreground size-4" />
                                                    <span className="font-medium">{pm.name}</span>
                                                    <button
                                                        onClick={() => handleAdminToggle(pm)}
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
                                                    {pm.requiresAttachment && (
                                                        <Badge variant="outline" className="gap-1.5 border-amber-200 bg-amber-50 text-amber-700">
                                                            <Paperclip className="size-3" />
                                                            تتطلب إيصال
                                                        </Badge>
                                                    )}
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
                        ) : (
                            /* Branch-admin: toggle switches to enable/disable global methods */
                            <div className="rounded-lg border">
                                <div className="border-b p-4">
                                    <h2 className="text-lg font-semibold">طرق الدفع</h2>
                                    <p className="text-muted-foreground text-sm">تفعيل أو تعطيل طرق الدفع المتاحة عند إنشاء الفواتير.</p>
                                </div>

                                {paymentMethods.length === 0 ? (
                                    <p className="text-muted-foreground p-6 text-center text-sm">
                                        لا توجد طرق دفع متاحة.
                                    </p>
                                ) : (
                                    <ul className="divide-y">
                                        {paymentMethods.map((pm) => (
                                            <li key={pm.id} className="flex items-center justify-between px-4 py-4">
                                                <div className="flex items-center gap-3">
                                                    <CreditCard className="text-muted-foreground size-4" />
                                                    <span className="font-medium">{pm.name}</span>
                                                </div>
                                                <Switch
                                                    checked={branchEnabledIds.includes(pm.id)}
                                                    onCheckedChange={(checked) => handleBranchToggle(pm.id, checked)}
                                                />
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        )}
                    </TabsContent>

                    {/* ── Loyalty Program ──────────────────────────────── */}
                    <TabsContent value="loyalty">
                        <div className="rounded-lg border p-6">
                            <h2 className="mb-1 text-lg font-semibold">برنامج الولاء</h2>
                            <p className="text-muted-foreground mb-4 text-sm">
                                تُطبَّق التغييرات على الفواتير الجديدة فقط؛ الأرصدة والفئات الحالية لا تتأثر.
                            </p>

                            {!loyaltyConfig || !canConfigureLoyalty ? (
                                <p className="text-muted-foreground text-sm">
                                    {canConfigureLoyalty
                                        ? 'إعداد برنامج الولاء غير متاح بدون فرع محدد.'
                                        : 'لا تملك صلاحية إعداد برنامج الولاء.'}
                                </p>
                            ) : (
                                <form onSubmit={submitLoyalty} className="space-y-6">
                                    <div className="flex items-center justify-between rounded-md border p-3">
                                        <div>
                                            <Label htmlFor="loyalty-active">تفعيل البرنامج</Label>
                                            <p className="text-muted-foreground text-xs">
                                                عند الإيقاف لا تُكتسب نقاط ولا تُطبَّق خصومات الفئات أو الاستبدال.
                                            </p>
                                        </div>
                                        <Switch
                                            id="loyalty-active"
                                            checked={loyaltyForm.data.is_active}
                                            onCheckedChange={(v) => loyaltyForm.setData('is_active', v)}
                                        />
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="space-y-1">
                                            <Label htmlFor="earning-rate">نقاط مكتسبة لكل 1 ر.س</Label>
                                            <Input
                                                id="earning-rate"
                                                type="number"
                                                step="0.0001"
                                                min="0"
                                                value={loyaltyForm.data.earning_rate}
                                                onChange={(e) => loyaltyForm.setData('earning_rate', e.target.value)}
                                            />
                                            <InputError message={loyaltyForm.errors.earning_rate} />
                                        </div>
                                        <div className="space-y-1">
                                            <Label htmlFor="redemption-rate">نقاط مقابل خصم 1 ر.س</Label>
                                            <Input
                                                id="redemption-rate"
                                                type="number"
                                                step="0.0001"
                                                min="0.01"
                                                value={loyaltyForm.data.redemption_rate}
                                                onChange={(e) => loyaltyForm.setData('redemption_rate', e.target.value)}
                                            />
                                            <InputError message={loyaltyForm.errors.redemption_rate} />
                                        </div>
                                        <div className="space-y-1">
                                            <Label htmlFor="min-redemption">الحد الأدنى للاستبدال (نقاط)</Label>
                                            <Input
                                                id="min-redemption"
                                                type="number"
                                                min="0"
                                                value={loyaltyForm.data.min_redemption_points}
                                                onChange={(e) => loyaltyForm.setData('min_redemption_points', e.target.value)}
                                            />
                                            <InputError message={loyaltyForm.errors.min_redemption_points} />
                                        </div>
                                        <div className="space-y-1">
                                            <Label htmlFor="expiry-months">انتهاء صلاحية النقاط (أشهر)</Label>
                                            <Input
                                                id="expiry-months"
                                                type="number"
                                                min="1"
                                                max="120"
                                                placeholder="بلا انتهاء"
                                                value={loyaltyForm.data.expiry_months}
                                                onChange={(e) => loyaltyForm.setData('expiry_months', e.target.value)}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                يُصفَّر رصيد العميل إذا مضت هذه المدة بلا أي شراء. اتركه فارغاً فلا تنتهي النقاط.
                                            </p>
                                            <InputError message={loyaltyForm.errors.expiry_months} />
                                        </div>
                                    </div>

                                    <div>
                                        <h3 className="mb-3 text-sm font-semibold">فئات الولاء (حد الإنفاق التراكمي ونسبة الخصم)</h3>
                                        <div className="space-y-3">
                                            {([
                                                { key: 'bronze', label: 'برونزي' },
                                                { key: 'silver', label: 'فضي' },
                                                { key: 'gold', label: 'ذهبي' },
                                            ] as const).map((tier) => (
                                                <div key={tier.key} className="grid items-end gap-3 sm:grid-cols-[6rem_1fr_1fr]">
                                                    <span className="text-sm font-medium">{tier.label}</span>
                                                    <div className="space-y-1">
                                                        <Label htmlFor={`${tier.key}-threshold`}>حد الإنفاق (ر.س)</Label>
                                                        <Input
                                                            id={`${tier.key}-threshold`}
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            value={loyaltyForm.data[`${tier.key}_threshold`]}
                                                            onChange={(e) => loyaltyForm.setData(`${tier.key}_threshold`, e.target.value)}
                                                        />
                                                        <InputError message={loyaltyForm.errors[`${tier.key}_threshold`]} />
                                                    </div>
                                                    <div className="space-y-1">
                                                        <Label htmlFor={`${tier.key}-discount`}>نسبة الخصم (%)</Label>
                                                        <Input
                                                            id={`${tier.key}-discount`}
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            max="100"
                                                            value={loyaltyForm.data[`${tier.key}_discount_pct`]}
                                                            onChange={(e) => loyaltyForm.setData(`${tier.key}_discount_pct`, e.target.value)}
                                                        />
                                                        <InputError message={loyaltyForm.errors[`${tier.key}_discount_pct`]} />
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    </div>

                                    <Button type="submit" disabled={loyaltyForm.processing}>
                                        {loyaltyForm.processing ? 'جاري الحفظ...' : 'حفظ الإعدادات'}
                                    </Button>
                                </form>
                            )}
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
