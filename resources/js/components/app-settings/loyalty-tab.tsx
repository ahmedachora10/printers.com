import { index as appSettingsIndex, updateLoyalty } from '@/actions/App/Http/Controllers/AppSettingController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { type AppSettingsLoyaltyData } from '@/types/payment-method';
import { router, useForm } from '@inertiajs/react';

interface BranchOption {
    id: number;
    name: string;
}

interface Props {
    config: AppSettingsLoyaltyData | null;
    canConfigure: boolean;
    isSuperAdmin: boolean;
    /** فارغة لغير السوبر أدمن — من سواه مقيّد بفرعه فلا منتقي له. */
    branches: BranchOption[];
    /** الفرع المعروض: المختار للسوبر أدمن، وفرع المستخدم لمن سواه. */
    branchId: number | null;
}

const TIERS = [
    { key: 'bronze', label: 'برونزي' },
    { key: 'silver', label: 'فضي' },
    { key: 'gold', label: 'ذهبي' },
] as const;

/**
 * تاسك 52: «كيف يُفعَّل برنامج الولاء؟» — البرنامج مفعَّل افتراضياً لكل فرع،
 * لكن السوبر أدمن بلا فرع كان لا يرى إعداداته إطلاقاً. فصار له منتقي فرع يحمّل
 * إعدادات الفرع المختار ويحفظ عليها، ومدير الفرع يبقى على فرعه كما كان.
 */
export default function LoyaltyTab({ config, canConfigure, isSuperAdmin, branches, branchId }: Props) {
    const form = useForm({
        branch_id: branchId?.toString() ?? '',
        is_active: config?.isActive ?? true,
        earning_rate: config?.earningRate?.toString() ?? '1',
        redemption_rate: config?.redemptionRate?.toString() ?? '100',
        min_redemption_points: config?.minRedemptionPoints?.toString() ?? '500',
        expiry_months: config?.expiryMonths?.toString() ?? '',
        bronze_threshold: config?.bronzeThreshold?.toString() ?? '500',
        silver_threshold: config?.silverThreshold?.toString() ?? '2000',
        gold_threshold: config?.goldThreshold?.toString() ?? '5000',
        bronze_discount_pct: config?.bronzeDiscountPct?.toString() ?? '2',
        silver_discount_pct: config?.silverDiscountPct?.toString() ?? '5',
        gold_discount_pct: config?.goldDiscountPct?.toString() ?? '8',
    });

    // تبديل الفرع زيارةٌ جديدة تحمّل إعداداته من الخادم — والصفحة تعيد بناء
    // النموذج لأنها تُركّب هذا المكوّن بمفتاح الفرع، فلا تختلط قيم فرعين.
    function selectBranch(value: string) {
        router.get(
            appSettingsIndex.url(),
            { tab: 'loyalty', loyaltyBranch: value },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function handleSubmit(e: React.FormEvent) {
        e.preventDefault();
        form.put(updateLoyalty.url(), { preserveScroll: true });
    }

    const selectedBranchName = branches.find((b) => b.id === branchId)?.name;

    return (
        <div className="rounded-lg border p-6">
            <h2 className="mb-1 text-lg font-semibold">برنامج الولاء</h2>
            <p className="text-muted-foreground mb-2 text-sm">
                تُطبَّق التغييرات على الفواتير الجديدة فقط؛ الأرصدة والفئات الحالية لا تتأثر.
            </p>

            {/* شرح شروط الاكتساب: سؤال العميل كان «كيف يُفعَّل البرنامج؟» فلا
                يكفي المفتاح وحده — النقاط لها شروطٌ لا تظهر في أي حقل. */}
            <ul className="text-muted-foreground mb-4 list-disc space-y-1 pe-5 text-xs">
                <li>
                    البرنامج مفعَّل افتراضياً لكل فرع، ويُدار من هنا: المفتاح أدناه يوقفه أو يعيد تشغيله لهذا الفرع وحده.
                </li>
                <li>
                    النقاط تُكتسب تلقائياً عند <span className="font-medium">اعتماد الفاتورة (مدفوعة)</span> إذا كان لها{' '}
                    <span className="font-medium">عميل مرتبط من نوع «فرد»</span> و
                    <span className="font-medium">غير مرتبط بمندوب</span> — فواتير الشركات والمناديب تُسوّى بالعمولة لا بالنقاط.
                </li>
                <li>
                    <span className="font-medium">النقاط</span> تُحتسب على المبلغ{' '}
                    <span className="font-medium">صافياً من الضريبة</span> مع التقريب للأسفل؛ أمّا{' '}
                    <span className="font-medium">حدود الفئات</span> أدناه فتُقاس بالمبلغ{' '}
                    <span className="font-medium">شاملاً الضريبة</span> كما يدفعه العميل على الفاتورة.
                </li>
                <li>
                    العميل يأخذ الفئة متى <span className="font-medium">بلغ إنفاقه التراكمي حدّها</span> أو
                    تجاوزه، وتتبع الفئة إنفاقه هبوطاً كذلك — فمرتجعٌ يهبط بإنفاقه دون الحدّ يُنزله عن الفئة.
                </li>
            </ul>

            {!canConfigure ? (
                <p className="text-muted-foreground text-sm">لا تملك صلاحية إعداد برنامج الولاء.</p>
            ) : isSuperAdmin && branches.length === 0 ? (
                <p className="text-muted-foreground text-sm">لا توجد فروع مفعّلة لإعداد برنامج الولاء عليها.</p>
            ) : !config || branchId === null ? (
                <p className="text-muted-foreground text-sm">إعداد برنامج الولاء غير متاح بدون فرع محدد.</p>
            ) : (
                <form onSubmit={handleSubmit} className="space-y-6">
                    {isSuperAdmin && (
                        <div className="bg-muted/40 space-y-1 rounded-md border p-3">
                            <Label htmlFor="loyalty-branch">الفرع</Label>
                            <Select value={String(branchId)} onValueChange={selectBranch}>
                                <SelectTrigger id="loyalty-branch" className="w-full sm:w-72">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {branches.map((branch) => (
                                        <SelectItem key={branch.id} value={String(branch.id)}>
                                            {branch.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-muted-foreground text-xs">
                                الإعدادات أدناه تخصّ هذا الفرع وحده، وتُحفظ عليه.
                            </p>
                            <InputError message={form.errors.branch_id} />
                        </div>
                    )}

                    <div className="flex items-center justify-between rounded-md border p-3">
                        <div>
                            <Label htmlFor="loyalty-active">تفعيل البرنامج</Label>
                            <p className="text-muted-foreground text-xs">
                                عند الإيقاف لا تُكتسب نقاط ولا تُطبَّق خصومات الفئات أو الاستبدال.
                            </p>
                        </div>
                        <Switch
                            id="loyalty-active"
                            checked={form.data.is_active}
                            onCheckedChange={(v) => form.setData('is_active', v)}
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
                                value={form.data.earning_rate}
                                onChange={(e) => form.setData('earning_rate', e.target.value)}
                            />
                            <InputError message={form.errors.earning_rate} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="redemption-rate">نقاط مقابل خصم 1 ر.س</Label>
                            <Input
                                id="redemption-rate"
                                type="number"
                                step="0.0001"
                                min="0.01"
                                value={form.data.redemption_rate}
                                onChange={(e) => form.setData('redemption_rate', e.target.value)}
                            />
                            <InputError message={form.errors.redemption_rate} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="min-redemption">الحد الأدنى للاستبدال (نقاط)</Label>
                            <Input
                                id="min-redemption"
                                type="number"
                                min="0"
                                value={form.data.min_redemption_points}
                                onChange={(e) => form.setData('min_redemption_points', e.target.value)}
                            />
                            <InputError message={form.errors.min_redemption_points} />
                        </div>
                        <div className="space-y-1">
                            <Label htmlFor="expiry-months">انتهاء صلاحية النقاط (أشهر)</Label>
                            <Input
                                id="expiry-months"
                                type="number"
                                min="1"
                                max="120"
                                placeholder="بلا انتهاء"
                                value={form.data.expiry_months}
                                onChange={(e) => form.setData('expiry_months', e.target.value)}
                            />
                            <p className="text-muted-foreground text-xs">
                                يُصفَّر رصيد العميل إذا مضت هذه المدة بلا أي شراء. اتركه فارغاً فلا تنتهي النقاط.
                            </p>
                            <InputError message={form.errors.expiry_months} />
                        </div>
                    </div>

                    <div>
                        <h3 className="mb-3 text-sm font-semibold">فئات الولاء (حد الإنفاق التراكمي شاملاً الضريبة، ونسبة الخصم)</h3>
                        <div className="space-y-3">
                            {TIERS.map((tier) => (
                                <div key={tier.key} className="grid items-end gap-3 sm:grid-cols-[6rem_1fr_1fr]">
                                    <span className="text-sm font-medium">{tier.label}</span>
                                    <div className="space-y-1">
                                        <Label htmlFor={`${tier.key}-threshold`}>حد الإنفاق (ر.س)</Label>
                                        <Input
                                            id={`${tier.key}-threshold`}
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            value={form.data[`${tier.key}_threshold`]}
                                            onChange={(e) => form.setData(`${tier.key}_threshold`, e.target.value)}
                                        />
                                        <InputError message={form.errors[`${tier.key}_threshold`]} />
                                    </div>
                                    <div className="space-y-1">
                                        <Label htmlFor={`${tier.key}-discount`}>نسبة الخصم (%)</Label>
                                        <Input
                                            id={`${tier.key}-discount`}
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            max="100"
                                            value={form.data[`${tier.key}_discount_pct`]}
                                            onChange={(e) => form.setData(`${tier.key}_discount_pct`, e.target.value)}
                                        />
                                        <InputError message={form.errors[`${tier.key}_discount_pct`]} />
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <Button type="submit" disabled={form.processing}>
                        {form.processing
                            ? 'جاري الحفظ...'
                            : isSuperAdmin && selectedBranchName
                              ? `حفظ إعدادات ${selectedBranchName}`
                              : 'حفظ الإعدادات'}
                    </Button>
                </form>
            )}
        </div>
    );
}
