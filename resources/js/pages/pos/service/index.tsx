import { PosCartTable } from '@/components/pos/cart-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/utils';
import service from '@/routes/pos/service';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type PosAgent, type PosCustomer, type PosLoyalty, type PosPaymentMethod, type PosService, type ServiceCartLine } from '@/types/pos';
import { Head, router, usePage } from '@inertiajs/react';
import { Award, Paperclip, Printer, Save, Search, Tag, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'نقطة البيع', href: service.create().url },
    { title: 'فاتورة خدمة', href: service.create().url },
];

interface Props {
    services: PosService[];
    customers: PosCustomer[];
    agents: PosAgent[];
    paymentMethods: PosPaymentMethod[];
    vatPct: number;
    loyalty: PosLoyalty;
}

type InvoiceStatus = 'paid' | 'due';

interface AppliedCoupon {
    code: string;
    type: 'percentage' | 'fixed';
    value: number;
}

const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;

const lineTotal = (line: ServiceCartLine) => round2(line.qty * line.unitPrice * (1 - line.discountPct / 100));

export default function ServicePos({ services, customers, agents, paymentMethods, vatPct, loyalty }: Props) {
    const { props } = usePage<SharedData>();
    // Employees may only raise DUE (معلق) invoices for an accountant to review;
    // the paid/due toggle is hidden for them and the status is locked to 'due'.
    const isEmployee = props.auth.role === 'employee';
    const [search, setSearch] = useState('');
    const [searchFocused, setSearchFocused] = useState(false);
    const [cart, setCart] = useState<ServiceCartLine[]>([]);
    const [customerId, setCustomerId] = useState<string>('none');
    const [agentId, setAgentId] = useState<string>('none');
    const [walkinName, setWalkinName] = useState('');
    const [walkinPhone, setWalkinPhone] = useState('');
    const [status, setStatus] = useState<InvoiceStatus>(isEmployee ? 'due' : 'paid');
    const [paymentMethodId, setPaymentMethodId] = useState<number | null>(null);
    const [receipt, setReceipt] = useState<File | null>(null);
    const [couponCode, setCouponCode] = useState('');
    const [appliedCoupon, setAppliedCoupon] = useState<AppliedCoupon | null>(null);
    const [couponLoading, setCouponLoading] = useState(false);
    const [redeemPoints, setRedeemPoints] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const lineSeq = useRef(0);

    useEffect(() => {
        if (props.success) {
            toast.success(props.success as string);
        }
    }, [props.success]);

    // Auto-fill the agent from the chosen customer's link; the cashier can still
    // change or clear it afterwards.
    useEffect(() => {
        setRedeemPoints('');
        if (customerId === 'none') {
            setAgentId('none');
            return;
        }
        const customer = customers.find((c) => String(c.id) === customerId);
        setAgentId(customer?.agentId ? String(customer.agentId) : 'none');
    }, [customerId, customers]);

    const filteredServices = useMemo(() => {
        const term = search.trim().toLowerCase();
        if (!term) return [];
        return services.filter((s) => s.name.toLowerCase().includes(term)).slice(0, 8);
    }, [services, search]);

    // Searchable customer/agent options for the cmdk combobox — walk-in / no-agent
    // sentinels sit first so the cashier can return to them from the list.
    const customerOptions = useMemo<ComboboxOption[]>(
        () => [
            { value: 'none', label: '— عميل عابر —' },
            ...customers.map((c) => ({ value: String(c.id), label: `${c.fullName} — ${c.phone}` })),
        ],
        [customers],
    );
    const agentOptions = useMemo<ComboboxOption[]>(
        () => [
            { value: 'none', label: '— بدون وكيل —' },
            ...agents.map((a) => ({ value: String(a.id), label: `${a.name} (${a.discountMode === 'rebate' ? 'عمولة' : 'خصم'} ${a.rate}%)` })),
        ],
        [agents],
    );

    const subtotal = useMemo(() => round2(cart.reduce((sum, l) => sum + lineTotal(l), 0)), [cart]);
    const commission = useMemo(() => round2(cart.reduce((sum, l) => sum + (lineTotal(l) * l.baseCommissionPct) / 100, 0)), [cart]);
    const selectedCustomer = useMemo(
        () => (customerId === 'none' ? null : (customers.find((c) => String(c.id) === customerId) ?? null)),
        [customerId, customers],
    );
    const selectedAgent = useMemo(() => (agentId === 'none' ? null : (agents.find((a) => String(a.id) === agentId) ?? null)), [agentId, agents]);
    const selectedPaymentMethod = useMemo(() => paymentMethods.find((m) => m.id === paymentMethodId) ?? null, [paymentMethods, paymentMethodId]);
    const requiresReceipt = selectedPaymentMethod?.requiresAttachment ?? false;

    // Loyalty benefits apply only to an eligible customer with no agent on the
    // invoice. Pipeline mirrors the server: subtotal → tier → coupon → agent → points.
    const loyaltyOn = loyalty.active && !!selectedCustomer?.loyaltyEligible && agentId === 'none';

    const tierDiscount = useMemo(
        () => (loyaltyOn && selectedCustomer ? round2((subtotal * selectedCustomer.tierDiscountPct) / 100) : 0),
        [loyaltyOn, selectedCustomer, subtotal],
    );
    const afterTier = useMemo(() => round2(subtotal - tierDiscount), [subtotal, tierDiscount]);
    const couponDiscount = useMemo(() => {
        if (!appliedCoupon) return 0;
        const raw = appliedCoupon.type === 'percentage' ? (afterTier * appliedCoupon.value) / 100 : appliedCoupon.value;
        return round2(Math.min(raw, afterTier));
    }, [appliedCoupon, afterTier]);
    const afterCoupon = useMemo(() => round2(afterTier - couponDiscount), [afterTier, couponDiscount]);
    const agentDiscount = useMemo(
        () => (selectedAgent?.discountMode === 'discount' ? round2((afterCoupon * selectedAgent.rate) / 100) : 0),
        [selectedAgent, afterCoupon],
    );
    const afterAgent = useMemo(() => round2(afterCoupon - agentDiscount), [afterCoupon, agentDiscount]);
    const pointsDiscount = useMemo(() => {
        if (!loyaltyOn || !loyalty.redemptionRate) return 0;
        const pts = Number(redeemPoints) || 0;
        if (pts <= 0) return 0;
        return round2(Math.min(pts / loyalty.redemptionRate, afterAgent));
    }, [loyaltyOn, redeemPoints, loyalty.redemptionRate, afterAgent]);
    const taxableBase = useMemo(() => round2(afterAgent - pointsDiscount), [afterAgent, pointsDiscount]);
    const vatAmount = useMemo(() => round2((taxableBase * vatPct) / 100), [taxableBase, vatPct]);
    const total = useMemo(() => round2(taxableBase + vatAmount), [taxableBase, vatAmount]);
    const agentRebate = useMemo(
        () => (selectedAgent?.discountMode === 'rebate' ? round2((total * selectedAgent.rate) / 100) : 0),
        [selectedAgent, total],
    );

    function addService(s: PosService) {
        setCart((prev) => {
            const existing = prev.find((l) => l.branchServiceId === s.id);
            if (existing) {
                return prev.map((l) => (l.key === existing.key ? { ...l, qty: l.qty + 1 } : l));
            }
            lineSeq.current += 1;
            return [
                ...prev,
                {
                    key: `s-${s.id}-${lineSeq.current}`,
                    branchServiceId: s.id,
                    name: s.name,
                    unitPrice: 0,
                    qty: 1,
                    discountPct: 0,
                    maxDiscountPct: s.maxDiscountPct,
                    baseCommissionPct: s.baseCommissionPct,
                    isTahazir: s.isTahazir,
                    isManual: false,
                },
            ];
        });
        setSearch('');
    }

    function addManualLine() {
        lineSeq.current += 1;
        setCart((prev) => [
            ...prev,
            {
                key: `m-${lineSeq.current}`,
                branchServiceId: null,
                name: '',
                unitPrice: 0,
                qty: 1,
                discountPct: 0,
                maxDiscountPct: 0,
                baseCommissionPct: 0,
                isTahazir: false,
                isManual: true,
            },
        ]);
    }

    function updateLine(key: string, patch: Partial<ServiceCartLine>) {
        setCart((prev) => prev.map((l) => (l.key === key ? { ...l, ...patch } : l)));
    }

    function selectLineService(line: ServiceCartLine, branchServiceId: number) {
        const s = services.find((x) => x.id === branchServiceId);
        if (!s) return;
        const cap = s.maxDiscountPct > 0 ? s.maxDiscountPct : 100;
        updateLine(line.key, {
            branchServiceId: s.id,
            name: s.name,
            maxDiscountPct: s.maxDiscountPct,
            baseCommissionPct: s.baseCommissionPct,
            isTahazir: s.isTahazir,
            discountPct: Math.min(cap, line.discountPct),
        });
    }

    function changeQty(line: ServiceCartLine, delta: number) {
        const next = line.qty + delta;
        if (next < 1) return;
        updateLine(line.key, { qty: next });
    }

    function setDiscount(line: ServiceCartLine, value: number) {
        const cap = line.maxDiscountPct > 0 ? line.maxDiscountPct : 100;
        const clamped = Math.min(cap, Math.max(0, value || 0));
        if (value > cap) {
            toast.error(`الحد الأقصى للخصم على "${line.name}" هو ${cap}%`);
        }
        updateLine(line.key, { discountPct: clamped });
    }

    function removeLine(key: string) {
        setCart((prev) => prev.filter((l) => l.key !== key));
    }

    async function applyCoupon() {
        const code = couponCode.trim();
        if (!code) return;
        setCouponLoading(true);
        try {
            const res = await fetch(`/coupons/validate?code=${encodeURIComponent(code)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await res.json();
            if (data.valid) {
                setAppliedCoupon({ code, type: data.type, value: Number(data.value) });
                toast.success('تم تطبيق الكوبون');
            } else {
                setAppliedCoupon(null);
                toast.error('الكوبون غير صالح أو منتهي الصلاحية');
            }
        } catch {
            toast.error('تعذر التحقق من الكوبون');
        } finally {
            setCouponLoading(false);
        }
    }

    function removeCoupon() {
        setAppliedCoupon(null);
        setCouponCode('');
    }

    function resetForm() {
        setCart([]);
        setCustomerId('none');
        setWalkinName('');
        setWalkinPhone('');
        setPaymentMethodId(null);
        setReceipt(null);
        setStatus(isEmployee ? 'due' : 'paid');
        setRedeemPoints('');
        removeCoupon();
    }

    function submit(print: boolean) {
        if (cart.length === 0) {
            toast.error('أضف خدمة واحدة على الأقل');
            return;
        }
        if (cart.some((l) => l.isManual && !l.branchServiceId)) {
            toast.error('اختر خدمة لكل سطر يدوي');
            return;
        }
        if (requiresReceipt && !receipt) {
            toast.error('يجب إرفاق إيصال التحويل لطريقة الدفع المحددة');
            return;
        }
        setSubmitting(true);
        setErrors({});
        router.post(
            service.store().url,
            {
                customer_id: customerId === 'none' ? null : Number(customerId),
                agent_id: agentId === 'none' ? null : Number(agentId),
                walkin_name: customerId === 'none' ? walkinName.trim() || null : null,
                walkin_phone: customerId === 'none' ? walkinPhone.trim() || null : null,
                coupon_code: appliedCoupon?.code ?? null,
                redeem_points: loyaltyOn && Number(redeemPoints) > 0 ? Number(redeemPoints) : null,
                payment_method_id: paymentMethodId,
                receipt,
                status,
                print,
                lines: cart.map((l) => ({
                    branch_service_id: l.branchServiceId,
                    qty: l.qty,
                    unit_price: l.unitPrice,
                    discount_pct: l.discountPct,
                })),
            },
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => resetForm(),
                onError: (e) => setErrors(e as Record<string, string>),
                onFinish: () => setSubmitting(false),
            },
        );
    }

    const showResults = searchFocused && search.trim() !== '';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="نقطة البيع — فاتورة خدمة" />
            <Toaster position="top-center" richColors />

            <div className="grid gap-4 p-4 lg:grid-cols-3">
                {/* Sidebar — customer, status, coupon, totals, payment, actions */}
                <div className="space-y-4 lg:col-span-1">
                    {/* Customer */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">العميل</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <Combobox
                                options={customerOptions}
                                value={customerId === 'none' ? '' : customerId}
                                onChange={(v) => setCustomerId(v || 'none')}
                                placeholder="— عميل عابر —"
                                searchPlaceholder="بحث عن عميل (اسم/هاتف)"
                                emptyText="لا يوجد عميل مطابق"
                                triggerClassName="w-full"
                                className="w-[var(--radix-popover-trigger-width)] min-w-56"
                            />

                            {customerId === 'none' && (
                                <>
                                    <p className="text-muted-foreground text-center text-xs">– أو عميل عابر –</p>
                                    <Input value={walkinName} onChange={(e) => setWalkinName(e.target.value)} placeholder="اسم العميل" />
                                    <Input
                                        value={walkinPhone}
                                        onChange={(e) => setWalkinPhone(e.target.value)}
                                        placeholder="رقم الهاتف"
                                        inputMode="tel"
                                    />
                                </>
                            )}
                        </CardContent>
                    </Card>

                    {/* Agent */}
                    {agents.length > 0 && (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">الوكيل</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                <Combobox
                                    options={agentOptions}
                                    value={agentId === 'none' ? '' : agentId}
                                    onChange={(v) => setAgentId(v || 'none')}
                                    placeholder="— بدون وكيل —"
                                    searchPlaceholder="بحث عن وكيل"
                                    emptyText="لا يوجد وكيل مطابق"
                                    triggerClassName="w-full"
                                    className="w-[var(--radix-popover-trigger-width)] min-w-56"
                                />
                                {selectedAgent && (
                                    <p className="text-muted-foreground text-xs">
                                        {selectedAgent.discountMode === 'rebate'
                                            ? `عمولة مرتجعة ${selectedAgent.rate}% تُحتسب على الإجمالي`
                                            : `خصم ${selectedAgent.rate}% على الفاتورة`}
                                    </p>
                                )}
                                {errors.agent_id && <p className="text-destructive text-xs">{errors.agent_id}</p>}
                            </CardContent>
                        </Card>
                    )}

                    {/* Status */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">حالة الفاتورة</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {isEmployee ? (
                                <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400">
                                    تُحفظ الفاتورة كـ <span className="font-semibold">معلقة</span> ليراجعها المحاسب ويعتمد الدفع.
                                </div>
                            ) : (
                                <div className="grid grid-cols-2 gap-2">
                                    <Button type="button" variant={status === 'paid' ? 'default' : 'outline'} onClick={() => setStatus('paid')}>
                                        مدفوع
                                    </Button>
                                    <Button type="button" variant={status === 'due' ? 'default' : 'outline'} onClick={() => setStatus('due')}>
                                        معلق
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Coupon */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">كوبون خصم</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {appliedCoupon ? (
                                <div className="flex items-center justify-between gap-2 rounded-md border border-green-500/40 bg-green-500/10 p-2">
                                    <span className="flex items-center gap-1 text-sm font-medium text-green-700 dark:text-green-400">
                                        <Tag className="size-4" /> {appliedCoupon.code}
                                    </span>
                                    <button type="button" onClick={removeCoupon} className="text-muted-foreground hover:text-destructive">
                                        <X className="size-4" />
                                    </button>
                                </div>
                            ) : (
                                <div className="flex items-center gap-2">
                                    <Input
                                        value={couponCode}
                                        onChange={(e) => setCouponCode(e.target.value)}
                                        onKeyDown={(e) => e.key === 'Enter' && applyCoupon()}
                                        placeholder="أدخل الكوبون"
                                    />
                                    <Button type="button" variant="outline" onClick={applyCoupon} disabled={couponLoading || !couponCode.trim()}>
                                        تطبيق
                                    </Button>
                                </div>
                            )}
                            {errors.coupon_code && <p className="text-destructive mt-2 text-xs">{errors.coupon_code}</p>}
                        </CardContent>
                    </Card>

                    {/* Loyalty */}
                    {loyaltyOn && selectedCustomer && (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Award className="size-4" /> نقاط الولاء
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">الفئة</span>
                                    <span className="font-medium">
                                        {selectedCustomer.tierLabel}
                                        {selectedCustomer.tierDiscountPct > 0 && ` — خصم ${selectedCustomer.tierDiscountPct}%`}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between text-sm">
                                    <span className="text-muted-foreground">رصيد النقاط</span>
                                    <span className="font-medium">{selectedCustomer.pointsBalance.toLocaleString('en-US')}</span>
                                </div>
                                <Input
                                    value={redeemPoints}
                                    onChange={(e) => setRedeemPoints(e.target.value.replace(/[^0-9]/g, ''))}
                                    placeholder={`نقاط للاستبدال (الحد الأدنى ${loyalty.minRedemptionPoints})`}
                                    inputMode="numeric"
                                    disabled={selectedCustomer.pointsBalance < loyalty.minRedemptionPoints}
                                />
                                {selectedCustomer.pointsBalance < loyalty.minRedemptionPoints ? (
                                    <p className="text-muted-foreground text-xs">الرصيد أقل من الحد الأدنى للاستبدال.</p>
                                ) : (
                                    pointsDiscount > 0 && (
                                        <p className="text-xs text-green-600 dark:text-green-400">
                                            خصم {formatCurrency(pointsDiscount)} مقابل {Number(redeemPoints).toLocaleString('en-US')} نقطة
                                        </p>
                                    )
                                )}
                                {errors.redeem_points && <p className="text-destructive text-xs">{errors.redeem_points}</p>}
                            </CardContent>
                        </Card>
                    )}

                    {/* Totals */}
                    <Card>
                        <CardContent className="space-y-2 py-4 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">المجموع الفرعي</span>
                                <span>{formatCurrency(subtotal)}</span>
                            </div>
                            {tierDiscount > 0 && (
                                <div className="flex justify-between text-green-600 dark:text-green-400">
                                    <span>خصم الفئة{selectedCustomer ? ` (${selectedCustomer.tierLabel})` : ''}</span>
                                    <span>−{formatCurrency(tierDiscount)}</span>
                                </div>
                            )}
                            {couponDiscount > 0 && (
                                <div className="flex justify-between text-green-600 dark:text-green-400">
                                    <span>خصم الكوبون</span>
                                    <span>−{formatCurrency(couponDiscount)}</span>
                                </div>
                            )}
                            {agentDiscount > 0 && (
                                <div className="flex justify-between text-green-600 dark:text-green-400">
                                    <span>خصم الوكيل</span>
                                    <span>−{formatCurrency(agentDiscount)}</span>
                                </div>
                            )}
                            {pointsDiscount > 0 && (
                                <div className="flex justify-between text-green-600 dark:text-green-400">
                                    <span>استبدال النقاط</span>
                                    <span>−{formatCurrency(pointsDiscount)}</span>
                                </div>
                            )}
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">الضريبة ({vatPct}%)</span>
                                <span>{formatCurrency(vatAmount)}</span>
                            </div>
                            <Separator />
                            <div className="flex justify-between text-lg font-bold">
                                <span>الإجمالي</span>
                                <span>{formatCurrency(total)}</span>
                            </div>
                            {agentRebate > 0 && (
                                <div className="text-muted-foreground flex justify-between text-xs">
                                    <span>عمولة الوكيل المرتجعة</span>
                                    <span>{formatCurrency(agentRebate)}</span>
                                </div>
                            )}
                            <div className="text-muted-foreground flex justify-between text-xs">
                                <span>عمولة الموظف (تقديري)</span>
                                <span>{formatCurrency(commission)}</span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Payment method */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">طريقة الدفع</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {paymentMethods.length === 0 ? (
                                <p className="text-muted-foreground text-sm">لا توجد طرق دفع مفعّلة</p>
                            ) : (
                                <div className="space-y-3">
                                    <div className="grid grid-cols-2 gap-2">
                                        {paymentMethods.map((m) => (
                                            <Button
                                                key={m.id}
                                                type="button"
                                                variant={paymentMethodId === m.id ? 'default' : 'outline'}
                                                onClick={() => {
                                                    setReceipt(null);
                                                    setPaymentMethodId((prev) => (prev === m.id ? null : m.id));
                                                }}
                                            >
                                                {m.name}
                                            </Button>
                                        ))}
                                    </div>

                                    {requiresReceipt && (
                                        <div className="space-y-1.5 rounded-md border border-amber-500/40 bg-amber-500/10 p-3">
                                            <Label htmlFor="receipt" className="flex items-center gap-1.5 text-sm text-amber-700 dark:text-amber-400">
                                                <Paperclip className="size-4" /> إيصال التحويل (مطلوب)
                                            </Label>
                                            <Input
                                                id="receipt"
                                                type="file"
                                                accept="image/jpeg,image/png,image/webp,application/pdf"
                                                onChange={(e) => setReceipt(e.target.files?.[0] ?? null)}
                                                className="cursor-pointer"
                                            />
                                            <p className="text-muted-foreground text-xs">صورة (jpg, png, webp) أو ملف PDF — بحد أقصى 5 ميجابايت.</p>
                                            {errors.receipt && <p className="text-destructive text-xs">{errors.receipt}</p>}
                                        </div>
                                    )}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Actions */}
                    <div className="space-y-2">
                        <Button type="button" className="w-full" disabled={submitting || cart.length === 0} onClick={() => submit(false)}>
                            <Save className="size-4" /> حفظ الفاتورة
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full"
                            disabled={submitting || cart.length === 0}
                            onClick={() => submit(true)}
                        >
                            <Printer className="size-4" /> طباعة وحفظ
                        </Button>
                    </div>
                </div>

                {/* Main — search + line editor */}
                <div className="space-y-4 lg:col-span-2">
                    <div className="relative">
                        <Search className="text-muted-foreground absolute top-1/2 right-3 size-4 -translate-y-1/2" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onFocus={() => setSearchFocused(true)}
                            onBlur={() => setTimeout(() => setSearchFocused(false), 150)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' && filteredServices[0]) addService(filteredServices[0]);
                            }}
                            placeholder="بحث عن خدمة بالاسم..."
                            className="pr-9"
                        />
                        {showResults && (
                            <div className="bg-popover absolute z-20 mt-1 w-full overflow-hidden rounded-md border shadow-md">
                                {filteredServices.length === 0 ? (
                                    <p className="text-muted-foreground p-3 text-center text-sm">لا توجد خدمات مطابقة</p>
                                ) : (
                                    filteredServices.map((s) => (
                                        <button
                                            key={s.id}
                                            type="button"
                                            onMouseDown={(e) => e.preventDefault()}
                                            onClick={() => addService(s)}
                                            className="hover:bg-accent flex w-full items-center justify-between gap-2 p-3 text-right text-sm transition"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">{s.name}</p>
                                                <p className="text-muted-foreground text-xs">عمولة {s.baseCommissionPct}%</p>
                                            </div>
                                            {s.isTahazir && <Badge variant="secondary">تحضير</Badge>}
                                        </button>
                                    ))
                                )}
                            </div>
                        )}
                    </div>

                    {services.length > 0 && (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">الخدمات المتاحة</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="grid max-h-64 grid-cols-2 gap-2 overflow-y-auto sm:grid-cols-3">
                                    {services.map((s) => (
                                        <button
                                            key={s.id}
                                            type="button"
                                            onClick={() => addService(s)}
                                            className="hover:bg-accent flex flex-col items-start gap-1 rounded-lg border p-3 text-right text-sm transition"
                                        >
                                            <span className="line-clamp-2 font-medium">{s.name}</span>
                                            <span className="flex w-full items-center justify-between gap-1">
                                                <span className="text-muted-foreground text-xs">عمولة {s.baseCommissionPct}%</span>
                                                {s.isTahazir && <Badge variant="secondary">تحضير</Badge>}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            </CardContent>
                        </Card>
                    )}

                    <Card className="min-h-[24rem]">
                        <CardContent className="py-4">
                            <PosCartTable
                                lines={cart}
                                itemLabel="الخدمة"
                                emptyHint="ابحث عن خدمة أو أضف سطر يدوي"
                                error={errors.lines}
                                isLineSelectable={(line) => line.isManual && !line.branchServiceId}
                                renderLineSelect={(line) => (
                                    <Select
                                        value={line.branchServiceId ? String(line.branchServiceId) : ''}
                                        onValueChange={(v) => selectLineService(line, Number(v))}
                                    >
                                        <SelectTrigger className="h-8">
                                            <SelectValue placeholder="اختر خدمة" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {services.map((s) => (
                                                <SelectItem key={s.id} value={String(s.id)}>
                                                    {s.name} — عمولة {s.baseCommissionPct}%
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                renderLineMeta={(line) => `عمولة ${line.baseCommissionPct}%`}
                                isPriceEditable={() => true}
                                getMaxDiscount={(line) => (line.maxDiscountPct > 0 ? line.maxDiscountPct : 100)}
                                getLineTotal={lineTotal}
                                onQtyChange={changeQty}
                                onPriceChange={(line, price) => updateLine(line.key, { unitPrice: price })}
                                onDiscountChange={setDiscount}
                                onRemove={removeLine}
                                onAddManual={addManualLine}
                            />
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
