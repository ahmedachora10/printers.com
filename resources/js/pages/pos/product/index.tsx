import { LineChip, LineField, LineReadout, LineSection, PosCartTable } from '@/components/pos/cart-table';
import { PosStickyTotalBar } from '@/components/pos/sticky-total-bar';
import { AsyncCombobox, type AsyncOption } from '@/components/ui/async-combobox';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/app-layout';
import { invoiceTotals } from '@/lib/invoice';
import { formatCurrency, formatQty } from '@/lib/utils';
import product from '@/routes/pos/product';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type CartLine, type PosAgent, type PosCustomer, type PosLoyalty, type PosPaymentMethod, type PosProduct } from '@/types/pos';
import { Head, router, usePage } from '@inertiajs/react';
import { Award, Paperclip, Printer, Ruler, Save, Search, Tag, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

/** Fetches branch customers from the async POS lookup, shaped for the picker. */
async function fetchCustomerOptions(query: string): Promise<AsyncOption<PosCustomer>[]> {
    const res = await fetch(`/pos/customers/search?q=${encodeURIComponent(query)}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (!res.ok) return [];
    const json = (await res.json()) as { data: PosCustomer[] };
    return json.data.map((c) => ({ value: String(c.id), label: `${c.fullName} — ${c.phone}`, data: c }));
}

const CUSTOMER_SENTINEL: AsyncOption<PosCustomer> = { value: 'none', label: '— عميل عابر —' };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'نقطة البيع', href: product.create().url },
    { title: 'فاتورة منتجات', href: product.create().url },
];

interface Props {
    products: PosProduct[];
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

const lineTotal = (line: CartLine) => round2(line.qty * line.unitPrice * (1 - line.discountPct / 100));

/** مساحة القطعة الواحدة بالمتر المربع من مقاسها بالسنتيمتر. */
const pieceAreaSqm = (line: CartLine) => ((line.widthCm ?? 0) / 100) * ((line.heightCm ?? 0) / 100);

/**
 * الكمية المحاسَب عليها لسطرٍ ما — مطابقة لاشتقاق الخادم حرفياً: سطر المتر
 * المربع كميته المساحة × عدد القطع (مدوّرة قبل الضرب في السعر)، وسواه عدد قطعه.
 */
const derivedQty = (line: CartLine) => (line.isSqm ? round2(pieceAreaSqm(line) * line.pieces) : line.qty);


export default function ProductPos({ products, agents, paymentMethods, vatPct, loyalty }: Props) {
    const { props } = usePage<SharedData>();
    const [search, setSearch] = useState('');
    const [searchFocused, setSearchFocused] = useState(false);
    const [cart, setCart] = useState<CartLine[]>([]);
    // The chosen customer is held in full (fetched on demand) rather than looked up
    // from a preloaded list, so 10k+ customers never ship to the browser.
    const [selectedCustomer, setSelectedCustomer] = useState<PosCustomer | null>(null);
    const customerId = selectedCustomer ? String(selectedCustomer.id) : 'none';
    // No agent picker on this screen — the agent comes from the customer's own
    // link and still drives the invoice discount / rebate.
    const [agentId, setAgentId] = useState<string>('none');
    const [walkinName, setWalkinName] = useState('');
    const [walkinPhone, setWalkinPhone] = useState('');
    const [status, setStatus] = useState<InvoiceStatus>('paid');
    const [paymentMethodId, setPaymentMethodId] = useState<number | null>(null);
    const [receipt, setReceipt] = useState<File | null>(null);
    const [couponCode, setCouponCode] = useState('');
    const [appliedCoupon, setAppliedCoupon] = useState<AppliedCoupon | null>(null);
    const [couponLoading, setCouponLoading] = useState(false);
    const [redeemPoints, setRedeemPoints] = useState('');
    // Remark about the whole order, printed under the lines table.
    const [notes, setNotes] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const manualSeq = useRef(0);

    useEffect(() => {
        if (props.success) {
            toast.success(props.success as string);
        }
    }, [props.success]);

    // Auto-fill the agent from the chosen customer's link; the cashier can still
    // change or clear it afterwards.
    useEffect(() => {
        setRedeemPoints('');
        setAgentId(selectedCustomer?.agentId ? String(selectedCustomer.agentId) : 'none');
    }, [selectedCustomer]);

    const fetchCustomers = useCallback(fetchCustomerOptions, []);

    const filteredProducts = useMemo(() => {
        const term = search.trim().toLowerCase();
        if (!term) return [];
        return products.filter((p) => p.name.toLowerCase().includes(term) || p.sku.toLowerCase().includes(term)).slice(0, 8);
    }, [products, search]);

    const subtotal = useMemo(() => round2(cart.reduce((sum, l) => sum + lineTotal(l), 0)), [cart]);
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
        () =>
            selectedAgent?.discountMode === 'discount'
                ? round2(Math.min(selectedAgent.discountType === 'fixed' ? selectedAgent.rate : (afterCoupon * selectedAgent.rate) / 100, afterCoupon))
                : 0,
        [selectedAgent, afterCoupon],
    );
    const afterAgent = useMemo(() => round2(afterCoupon - agentDiscount), [afterCoupon, agentDiscount]);
    const pointsDiscount = useMemo(() => {
        if (!loyaltyOn || !loyalty.redemptionRate) return 0;
        const pts = Number(redeemPoints) || 0;
        if (pts <= 0) return 0;
        return round2(Math.min(pts / loyalty.redemptionRate, afterAgent));
    }, [loyaltyOn, redeemPoints, loyalty.redemptionRate, afterAgent]);
    // الأسعار المُدخلة شاملة للضريبة: ما يبقى بعد الخصومات هو الإجمالي الذي
    // يدفعه العميل، والضريبة تُستخرج من داخله بالطرح. مطابق للخادم حرفياً.
    const total = useMemo(() => round2(afterAgent - pointsDiscount), [afterAgent, pointsDiscount]);
    // The rebate is earned on the value net of VAT — mirrors the server.
    const netBeforeVat = useMemo(() => round2(total / (1 + vatPct / 100)), [total, vatPct]);
    const vatAmount = useMemo(() => round2(total - netBeforeVat), [total, netBeforeVat]);
    // تفكيك ما يُعرض للكاشير — نفس اشتقاق شاشة الفاتورة والطباعة.
    const totals = useMemo(
        () =>
            invoiceTotals({
                vatPct,
                vatAmount,
                totalAmount: total,
                discounts: [tierDiscount, couponDiscount, agentDiscount, pointsDiscount],
            }),
        [vatPct, vatAmount, total, tierDiscount, couponDiscount, agentDiscount, pointsDiscount],
    );
    const agentRebate = useMemo(
        () =>
            selectedAgent?.discountMode === 'rebate'
                ? round2(
                      Math.min(selectedAgent.discountType === 'fixed' ? selectedAgent.rate : (netBeforeVat * selectedAgent.rate) / 100, netBeforeVat),
                  )
                : 0,
        [selectedAgent, netBeforeVat],
    );

    function addProduct(p: PosProduct) {
        if (p.currentStock <= 0) {
            toast.error(`لا يوجد مخزون متاح من "${p.name}"`);
            return;
        }
        setCart((prev) => {
            const existing = prev.find((l) => l.productId === p.id);
            if (existing) {
                // سطر المتر المربع تُزاد قطعُه لا كميتُه — المساحة تُشتقّ منها.
                if (existing.isSqm) {
                    const next = { ...existing, pieces: existing.pieces + 1 };
                    if (derivedQty(next) > p.currentStock) {
                        toast.error(`الكمية القصوى المتاحة من "${p.name}" هي ${formatQty(p.currentStock)} م²`);
                        return prev;
                    }
                    return prev.map((l) => (l.productId === p.id ? { ...next, qty: derivedQty(next) } : l));
                }
                if (existing.qty >= p.currentStock) {
                    toast.error(`الكمية القصوى المتاحة من "${p.name}" هي ${formatQty(p.currentStock)}`);
                    return prev;
                }
                return prev.map((l) => (l.productId === p.id ? { ...l, qty: l.qty + 1 } : l));
            }
            return [
                ...prev,
                {
                    key: `p-${p.id}`,
                    productId: p.id,
                    name: p.name,
                    sku: p.sku,
                    unitPrice: p.sellingPrice,
                    // سطر المتر المربع يبدأ بكمية صفر: لا مساحة قبل إدخال المقاس.
                    qty: p.isSqm ? 0 : 1,
                    discountPct: 0,
                    maxStock: p.currentStock,
                    unitName: p.unitName,
                    isManual: false,
                    isSqm: p.isSqm,
                    widthCm: null,
                    heightCm: null,
                    pieces: 1,
                },
            ];
        });
        setSearch('');
    }

    function addManualLine() {
        manualSeq.current += 1;
        setCart((prev) => [
            ...prev,
            {
                key: `m-${manualSeq.current}`,
                productId: null,
                name: '',
                sku: '',
                unitPrice: 0,
                qty: 1,
                discountPct: 0,
                maxStock: null,
                unitName: null,
                isManual: true,
                isSqm: false,
                widthCm: null,
                heightCm: null,
                pieces: 1,
            },
        ]);
    }

    function updateLine(key: string, patch: Partial<CartLine>) {
        setCart((prev) => prev.map((l) => (l.key === key ? { ...l, ...patch } : l)));
    }

    function selectLineProduct(line: CartLine, productId: number) {
        const p = products.find((x) => x.id === productId);
        if (!p) return;
        updateLine(line.key, {
            productId: p.id,
            name: p.name,
            sku: p.sku,
            unitPrice: p.sellingPrice,
            maxStock: p.currentStock,
            unitName: p.unitName,
            isSqm: p.isSqm,
            // منتج بالمتر المربع: الكمية تنتظر المقاس؛ ومنتج بالقطعة يعود لكميته.
            ...(p.isSqm
                ? { qty: 0, widthCm: null, heightCm: null, pieces: 1 }
                : {
                      widthCm: null,
                      heightCm: null,
                      qty: line.maxStock !== null && line.qty > p.currentStock ? Math.max(1, p.currentStock) : Math.max(1, line.qty),
                  }),
        });
    }

    function changeQty(line: CartLine, delta: number) {
        const next = line.qty + delta;
        if (next < 1) return;
        if (line.maxStock !== null && next > line.maxStock) {
            toast.error(`الكمية القصوى المتاحة هي ${formatQty(line.maxStock)}`);
            return;
        }
        updateLine(line.key, { qty: next });
    }

    /**
     * تحديث مقاس سطر المتر المربع أو عدد قطعه، وإعادة اشتقاق كميته معه — فالكمية
     * هي ما يُحاسَب عليه وما يُخصم من المخزون، ولا تُكتب يدوياً على هذا السطر.
     */
    function setSqmLine(line: CartLine, patch: Partial<Pick<CartLine, 'widthCm' | 'heightCm' | 'pieces'>>) {
        const next = { ...line, ...patch };
        const qty = derivedQty(next);

        if (line.maxStock !== null && qty > line.maxStock) {
            toast.error(`الكمية القصوى المتاحة هي ${formatQty(line.maxStock)} م²`);
            return;
        }

        updateLine(line.key, { ...patch, qty });
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
        setSelectedCustomer(null);
        setWalkinName('');
        setWalkinPhone('');
        setPaymentMethodId(null);
        setReceipt(null);
        setStatus('paid');
        setRedeemPoints('');
        setNotes('');
        removeCoupon();
    }

    function submit(print: boolean) {
        if (cart.length === 0) {
            toast.error('أضف منتجاً واحداً على الأقل');
            return;
        }
        if (cart.some((l) => l.isManual && !l.productId)) {
            toast.error('اختر منتجاً لكل سطر يدوي');
            return;
        }
        if (cart.some((l) => l.isSqm && (!l.widthCm || !l.heightCm))) {
            toast.error('أدخل العرض والطول لكل منتج مسعّر بالمتر المربع');
            return;
        }
        if (requiresReceipt && !receipt) {
            toast.error('يجب إرفاق إيصال التحويل لطريقة الدفع المحددة');
            return;
        }
        setSubmitting(true);
        setErrors({});
        router.post(
            product.store().url,
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
                notes: notes.trim() || null,
                lines: cart.map((l) => ({
                    product_id: l.productId,
                    name: l.productId ? null : l.name.trim(),
                    // كمية سطر المتر المربع يعيد الخادم اشتقاقها من المقاس؛ تُرسل
                    // هنا لأن التحقق يطلبها، والمعتمَد ما يحسبه الخادم.
                    qty: l.qty,
                    width_cm: l.isSqm ? l.widthCm : null,
                    height_cm: l.isSqm ? l.heightCm : null,
                    pieces: l.isSqm ? l.pieces : null,
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
            <Head title="نقطة البيع — فاتورة منتجات" />
            <Toaster position="top-center" richColors />

            {/* pb-24 below lg clears the fixed total bar at the bottom. */}
            <div className="grid gap-4 p-4 pb-24 lg:grid-cols-3 lg:pb-4">
                {/* Sidebar — customer, status, coupon, totals, payment, actions.
                    Below lg it follows the cart, so the cashier starts on the
                    lines instead of scrolling past every setting first. */}
                <div className="order-2 space-y-4 lg:order-none lg:col-span-1">
                    {/* Customer */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">العميل</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <AsyncCombobox<PosCustomer>
                                fetcher={fetchCustomers}
                                value={customerId}
                                selectedLabel={selectedCustomer ? `${selectedCustomer.fullName} — ${selectedCustomer.phone}` : undefined}
                                onChange={(_v, option) => setSelectedCustomer(option?.data ?? null)}
                                sentinel={CUSTOMER_SENTINEL}
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

                    {/* Status */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">حالة الفاتورة</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-2 gap-2">
                                <Button type="button" variant={status === 'paid' ? 'default' : 'outline'} onClick={() => setStatus('paid')}>
                                    مدفوع
                                </Button>
                                <Button type="button" variant={status === 'due' ? 'default' : 'outline'} onClick={() => setStatus('due')}>
                                    معلق
                                </Button>
                            </div>
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
                                <span>{formatCurrency(totals.subtotal)}</span>
                            </div>
                            {totals.discounts[0] > 0 && (
                                <div className="flex justify-between text-green-600 dark:text-green-400">
                                    <span>خصم الفئة{selectedCustomer ? ` (${selectedCustomer.tierLabel})` : ''}</span>
                                    <span>−{formatCurrency(totals.discounts[0])}</span>
                                </div>
                            )}
                            {totals.discounts[1] > 0 && (
                                <div className="flex justify-between text-green-600 dark:text-green-400">
                                    <span>خصم الكوبون</span>
                                    <span>−{formatCurrency(totals.discounts[1])}</span>
                                </div>
                            )}
                            {totals.discounts[2] > 0 && (
                                <div className="flex justify-between text-green-600 dark:text-green-400">
                                    <span>خصم المندوب</span>
                                    <span>−{formatCurrency(totals.discounts[2])}</span>
                                </div>
                            )}
                            {totals.discounts[3] > 0 && (
                                <div className="flex justify-between text-green-600 dark:text-green-400">
                                    <span>استبدال النقاط</span>
                                    <span>−{formatCurrency(totals.discounts[3])}</span>
                                </div>
                            )}
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">الضريبة ({vatPct}%)</span>
                                <span>{formatCurrency(totals.vatAmount)}</span>
                            </div>
                            <Separator />
                            <div className="flex justify-between text-lg font-bold">
                                <span>الإجمالي (شامل الضريبة)</span>
                                <span>{formatCurrency(totals.total)}</span>
                            </div>
                            {agentRebate > 0 && (
                                <div className="text-muted-foreground flex justify-between text-xs">
                                    <span>عمولة المندوب المرتجعة</span>
                                    <span>{formatCurrency(agentRebate)}</span>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Invoice-level note — printed under the whole lines table. */}
                    <Card>
                        <CardContent className="space-y-1.5 py-4">
                            <Label htmlFor="invoice-notes" className="text-sm">
                                ملاحظات للعميل (اختياري)
                            </Label>
                            <textarea
                                id="invoice-notes"
                                rows={2}
                                maxLength={1000}
                                value={notes}
                                onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => setNotes(e.target.value)}
                                placeholder="ملاحظة تخص الفاتورة كاملة — مثال: التسليم بعد 3 أيام عمل"
                                className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[56px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            />
                            <p className="text-muted-foreground text-xs">تُطبع أسفل جدول البنود في الفاتورة.</p>
                            {errors.notes && <p className="text-destructive text-xs">{errors.notes}</p>}
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
                <div className="order-1 space-y-4 lg:order-none lg:col-span-2">
                    <div className="relative">
                        <Search className="text-muted-foreground absolute top-1/2 right-3 size-4 -translate-y-1/2" />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onFocus={() => setSearchFocused(true)}
                            onBlur={() => setTimeout(() => setSearchFocused(false), 150)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' && filteredProducts[0]) addProduct(filteredProducts[0]);
                            }}
                            placeholder="بحث بالـ SKU أو اسم المنتج..."
                            className="pr-9"
                        />
                        {showResults && (
                            <div className="bg-popover absolute z-20 mt-1 w-full overflow-hidden rounded-md border shadow-md">
                                {filteredProducts.length === 0 ? (
                                    <p className="text-muted-foreground p-3 text-center text-sm">لا توجد منتجات مطابقة</p>
                                ) : (
                                    filteredProducts.map((p) => (
                                        <button
                                            key={p.id}
                                            type="button"
                                            onMouseDown={(e) => e.preventDefault()}
                                            onClick={() => addProduct(p)}
                                            disabled={p.currentStock <= 0}
                                            className="hover:bg-accent flex w-full items-center justify-between gap-2 p-3 text-right text-sm transition disabled:opacity-50"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">{p.name}</p>
                                                <p className="text-muted-foreground text-xs">{p.sku}</p>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <span className="text-primary font-semibold">
                                                    {formatCurrency(p.sellingPrice)}
                                                    {p.isSqm && <span className="text-muted-foreground text-[11px]"> / م²</span>}
                                                </span>
                                                <Badge variant={p.currentStock <= 0 ? 'destructive' : 'secondary'}>
                                                    {formatQty(p.currentStock)}
                                                    {p.isSqm && ' م²'}
                                                </Badge>
                                            </div>
                                        </button>
                                    ))
                                )}
                            </div>
                        )}
                    </div>

                    <Card className="min-h-[24rem]">
                        <CardContent className="py-4">
                            <PosCartTable
                                lines={cart}
                                itemLabel="المنتج"
                                emptyHint="ابحث عن منتج بالـ SKU أو أضف سطر يدوي"
                                error={errors.lines}
                                isLineSelectable={(line) => line.isManual}
                                renderLineSelect={(line) => (
                                    <Select
                                        value={line.productId ? String(line.productId) : ''}
                                        onValueChange={(v) => selectLineProduct(line, Number(v))}
                                    >
                                        <SelectTrigger className="h-11 md:h-8">
                                            <SelectValue placeholder="اختر منتجاً" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {products.map((p) => (
                                                <SelectItem key={p.id} value={String(p.id)} disabled={p.currentStock <= 0}>
                                                    {p.name} — {formatCurrency(p.sellingPrice)}
                                                    {p.isSqm && ' / م²'} (متوفر: {formatQty(p.currentStock)})
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                renderLineMeta={(line) => (line.isSqm ? `${line.sku} • بالمتر المربع` : line.sku)}
                                isPriceEditable={(line) => line.isManual}
                                getMaxDiscount={() => 100}
                                getLineTotal={lineTotal}
                                // كمية سطر المتر المربع مشتقّة من المقاس، فلا مِعداد لها.
                                renderQtyControl={(line) =>
                                    line.isSqm ? (
                                        <LineReadout tone={line.qty > 0 ? 'info' : 'neutral'}>{formatQty(line.qty)} م²</LineReadout>
                                    ) : undefined
                                }
                                onQtyChange={changeQty}
                                onPriceChange={(line, price) => updateLine(line.key, { unitPrice: price })}
                                onDiscountChange={(line, value) => updateLine(line.key, { discountPct: Math.min(100, Math.max(0, value || 0)) })}
                                onRemove={removeLine}
                                onAddManual={addManualLine}
                                // سطر المتر المربع بلا كمية حتى يُدخَل مقاسه — يُفتح فوراً
                                // كي لا يبحث الكاشير عن الحقول.
                                isLineDetailsInitiallyOpen={(line) => line.isSqm && (!line.widthCm || !line.heightCm)}
                                renderLineSummary={(line) => {
                                    if (!line.isSqm) return null;
                                    const hasDimensions = !!line.widthCm && !!line.heightCm;

                                    return hasDimensions ? (
                                        <LineChip>
                                            <Ruler className="size-3" />
                                            {line.widthCm}×{line.heightCm} سم × {line.pieces} = {formatQty(line.qty)} م²
                                        </LineChip>
                                    ) : (
                                        <LineChip tone="warning">
                                            <Ruler className="size-3" /> أدخل الأبعاد
                                        </LineChip>
                                    );
                                }}
                                renderLineDetails={(line) => {
                                    if (!line.isSqm) return null;

                                    return (
                                        <LineSection title="المقاس" aside={`سعر المتر ${formatCurrency(line.unitPrice)}`}>
                                            <div className="grid gap-3 sm:grid-cols-4">
                                                <LineField label="العرض (سم)" htmlFor={`p-width-${line.key}`}>
                                                    <Input
                                                        id={`p-width-${line.key}`}
                                                        type="number"
                                                        min={0}
                                                        step="0.1"
                                                        value={line.widthCm ?? ''}
                                                        onChange={(e) =>
                                                            setSqmLine(line, {
                                                                widthCm: e.target.value === '' ? null : Math.max(0, Number(e.target.value)),
                                                            })
                                                        }
                                                        className="h-9 text-center"
                                                        placeholder="0"
                                                    />
                                                </LineField>
                                                <LineField label="الطول (سم)" htmlFor={`p-height-${line.key}`}>
                                                    <Input
                                                        id={`p-height-${line.key}`}
                                                        type="number"
                                                        min={0}
                                                        step="0.1"
                                                        value={line.heightCm ?? ''}
                                                        onChange={(e) =>
                                                            setSqmLine(line, {
                                                                heightCm: e.target.value === '' ? null : Math.max(0, Number(e.target.value)),
                                                            })
                                                        }
                                                        className="h-9 text-center"
                                                        placeholder="0"
                                                    />
                                                </LineField>
                                                <LineField label="عدد القطع" htmlFor={`p-pieces-${line.key}`}>
                                                    <Input
                                                        id={`p-pieces-${line.key}`}
                                                        type="number"
                                                        min={1}
                                                        step="1"
                                                        value={line.pieces}
                                                        onChange={(e) => setSqmLine(line, { pieces: Math.max(1, Number(e.target.value) || 1) })}
                                                        className="h-9 text-center"
                                                    />
                                                </LineField>
                                                <LineField label="المساحة المخصومة">
                                                    <LineReadout tone="info">{formatQty(line.qty)} م²</LineReadout>
                                                </LineField>
                                            </div>
                                            {line.maxStock !== null && (
                                                <p className="text-muted-foreground text-[11px]">
                                                    المتاح في المخزون: {formatQty(line.maxStock)} م²
                                                </p>
                                            )}
                                        </LineSection>
                                    );
                                }}
                            />
                        </CardContent>
                    </Card>
                </div>
            </div>

            <PosStickyTotalBar
                total={total}
                lineCount={cart.length}
                saveLabel="حفظ الفاتورة"
                disabled={submitting || cart.length === 0}
                onSave={() => submit(false)}
            />
        </AppLayout>
    );
}
