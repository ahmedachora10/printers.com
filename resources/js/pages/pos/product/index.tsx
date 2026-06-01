import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/app-layout';
import { formatCurrency } from '@/lib/utils';
import product from '@/routes/pos/product';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type CartLine, type PosCustomer, type PosPaymentMethod, type PosProduct } from '@/types/pos';
import { Head, router, usePage } from '@inertiajs/react';
import { FileText, Minus, Plus, Printer, Save, Search, Tag, Trash2, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'نقطة البيع', href: product.create().url },
    { title: 'فاتورة منتجات', href: product.create().url },
];

interface Props {
    products: PosProduct[];
    customers: PosCustomer[];
    paymentMethods: PosPaymentMethod[];
    vatPct: number;
}

type InvoiceStatus = 'paid' | 'due';

interface AppliedCoupon {
    code: string;
    type: 'percentage' | 'fixed';
    value: number;
}

const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;

const lineTotal = (line: CartLine) => round2(line.qty * line.unitPrice * (1 - line.discountPct / 100));

export default function ProductPos({ products, customers, paymentMethods, vatPct }: Props) {
    const { props } = usePage<SharedData>();
    const [search, setSearch] = useState('');
    const [searchFocused, setSearchFocused] = useState(false);
    const [cart, setCart] = useState<CartLine[]>([]);
    const [customerId, setCustomerId] = useState<string>('none');
    const [walkinName, setWalkinName] = useState('');
    const [walkinPhone, setWalkinPhone] = useState('');
    const [status, setStatus] = useState<InvoiceStatus>('paid');
    const [paymentMethodId, setPaymentMethodId] = useState<number | null>(null);
    const [couponCode, setCouponCode] = useState('');
    const [appliedCoupon, setAppliedCoupon] = useState<AppliedCoupon | null>(null);
    const [couponLoading, setCouponLoading] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const manualSeq = useRef(0);

    useEffect(() => {
        if (props.success) {
            toast.success(props.success as string);
        }
    }, [props.success]);

    const filteredProducts = useMemo(() => {
        const term = search.trim().toLowerCase();
        if (!term) return [];
        return products.filter((p) => p.name.toLowerCase().includes(term) || p.sku.toLowerCase().includes(term)).slice(0, 8);
    }, [products, search]);

    const subtotal = useMemo(() => round2(cart.reduce((sum, l) => sum + lineTotal(l), 0)), [cart]);
    const couponDiscount = useMemo(() => {
        if (!appliedCoupon) return 0;
        const raw = appliedCoupon.type === 'percentage' ? (subtotal * appliedCoupon.value) / 100 : appliedCoupon.value;
        return round2(Math.min(raw, subtotal));
    }, [appliedCoupon, subtotal]);
    const taxableBase = useMemo(() => round2(subtotal - couponDiscount), [subtotal, couponDiscount]);
    const vatAmount = useMemo(() => round2((taxableBase * vatPct) / 100), [taxableBase, vatPct]);
    const total = useMemo(() => round2(taxableBase + vatAmount), [taxableBase, vatAmount]);

    function addProduct(p: PosProduct) {
        if (p.currentStock <= 0) {
            toast.error(`لا يوجد مخزون متاح من "${p.name}"`);
            return;
        }
        setCart((prev) => {
            const existing = prev.find((l) => l.productId === p.id);
            if (existing) {
                if (existing.qty >= p.currentStock) {
                    toast.error(`الكمية القصوى المتاحة من "${p.name}" هي ${p.currentStock}`);
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
                    qty: 1,
                    discountPct: 0,
                    maxStock: p.currentStock,
                    unitName: p.unitName,
                    isManual: false,
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
            qty: line.maxStock !== null && line.qty > p.currentStock ? Math.max(1, p.currentStock) : line.qty,
        });
    }

    function changeQty(line: CartLine, delta: number) {
        const next = line.qty + delta;
        if (next < 1) return;
        if (line.maxStock !== null && next > line.maxStock) {
            toast.error(`الكمية القصوى المتاحة هي ${line.maxStock}`);
            return;
        }
        updateLine(line.key, { qty: next });
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
        setStatus('paid');
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
        setSubmitting(true);
        setErrors({});
        router.post(
            product.store().url,
            {
                customer_id: customerId === 'none' ? null : Number(customerId),
                walkin_name: customerId === 'none' ? walkinName.trim() || null : null,
                walkin_phone: customerId === 'none' ? walkinPhone.trim() || null : null,
                coupon_code: appliedCoupon?.code ?? null,
                payment_method_id: paymentMethodId,
                status,
                print,
                lines: cart.map((l) => ({
                    product_id: l.productId,
                    name: l.productId ? null : l.name.trim(),
                    qty: l.qty,
                    unit_price: l.unitPrice,
                    discount_pct: l.discountPct,
                })),
            },
            {
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

            <div className="grid gap-4 p-4 lg:grid-cols-3">
                {/* Sidebar — customer, status, coupon, totals, payment, actions */}
                <div className="space-y-4 lg:col-span-1">
                    {/* Customer */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">العميل</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <Select value={customerId} onValueChange={setCustomerId}>
                                <SelectTrigger>
                                    <SelectValue placeholder="بحث عن عميل (اسم/هاتف)" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">— عميل عابر —</SelectItem>
                                    {customers.map((c) => (
                                        <SelectItem key={c.id} value={String(c.id)}>
                                            {c.fullName} — {c.phone}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>

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

                    {/* Totals */}
                    <Card>
                        <CardContent className="space-y-2 py-4 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">المجموع الفرعي</span>
                                <span>{formatCurrency(subtotal)}</span>
                            </div>
                            {couponDiscount > 0 && (
                                <div className="flex justify-between text-green-600 dark:text-green-400">
                                    <span>خصم الكوبون</span>
                                    <span>−{formatCurrency(couponDiscount)}</span>
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
                                <div className="grid grid-cols-2 gap-2">
                                    {paymentMethods.map((m) => (
                                        <Button
                                            key={m.id}
                                            type="button"
                                            variant={paymentMethodId === m.id ? 'default' : 'outline'}
                                            onClick={() => setPaymentMethodId((prev) => (prev === m.id ? null : m.id))}
                                        >
                                            {m.name}
                                        </Button>
                                    ))}
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
                                                <span className="text-primary font-semibold">{formatCurrency(p.sellingPrice)}</span>
                                                <Badge variant={p.currentStock <= 0 ? 'destructive' : 'secondary'}>{p.currentStock}</Badge>
                                            </div>
                                        </button>
                                    ))
                                )}
                            </div>
                        )}
                    </div>

                    <Card className="min-h-[24rem]">
                        <CardContent className="py-4">
                            {errors.lines && <p className="bg-destructive/10 text-destructive mb-3 rounded-md p-2 text-sm">{errors.lines}</p>}

                            {cart.length === 0 ? (
                                <div className="text-muted-foreground flex flex-col items-center gap-3 py-16 text-center">
                                    <FileText className="size-12 opacity-40" />
                                    <p className="text-sm">ابحث عن منتج بالـ SKU أو أضف سطر يدوي</p>
                                    <Button type="button" variant="outline" size="sm" onClick={addManualLine}>
                                        <Plus className="size-4" /> سطر يدوي
                                    </Button>
                                </div>
                            ) : (
                                <div className="space-y-2">
                                    {cart.map((line) => (
                                        <div key={line.key} className="rounded-lg border p-3">
                                            <div className="flex items-start justify-between gap-2">
                                                <div className="min-w-0 flex-1">
                                                    {line.isManual ? (
                                                        <Select
                                                            value={line.productId ? String(line.productId) : ''}
                                                            onValueChange={(v) => selectLineProduct(line, Number(v))}
                                                        >
                                                            <SelectTrigger className="h-8">
                                                                <SelectValue placeholder="اختر منتجاً" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {products.map((p) => (
                                                                    <SelectItem key={p.id} value={String(p.id)} disabled={p.currentStock <= 0}>
                                                                        {p.name} — {formatCurrency(p.sellingPrice)} (متوفر: {p.currentStock})
                                                                    </SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                    ) : (
                                                        <>
                                                            <p className="truncate text-sm font-medium">{line.name}</p>
                                                            <p className="text-muted-foreground text-xs">{line.sku}</p>
                                                        </>
                                                    )}
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() => removeLine(line.key)}
                                                    className="text-muted-foreground hover:text-destructive shrink-0"
                                                >
                                                    <Trash2 className="size-4" />
                                                </button>
                                            </div>

                                            <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                                                {/* qty */}
                                                <div className="flex items-center gap-1">
                                                    <Button
                                                        type="button"
                                                        size="icon"
                                                        variant="outline"
                                                        className="size-7"
                                                        onClick={() => changeQty(line, -1)}
                                                    >
                                                        <Minus className="size-3" />
                                                    </Button>
                                                    <span className="w-8 text-center text-sm">{line.qty}</span>
                                                    <Button
                                                        type="button"
                                                        size="icon"
                                                        variant="outline"
                                                        className="size-7"
                                                        onClick={() => changeQty(line, 1)}
                                                    >
                                                        <Plus className="size-3" />
                                                    </Button>
                                                </div>

                                                {/* unit price */}
                                                <div className="flex items-center gap-1">
                                                    <Label className="text-muted-foreground text-xs">السعر</Label>
                                                    {line.isManual ? (
                                                        <Input
                                                            type="number"
                                                            min={0}
                                                            step="0.01"
                                                            value={line.unitPrice}
                                                            onChange={(e) =>
                                                                updateLine(line.key, { unitPrice: Math.max(0, Number(e.target.value) || 0) })
                                                            }
                                                            className="h-7 w-24 text-center"
                                                        />
                                                    ) : (
                                                        <span className="text-sm">{formatCurrency(line.unitPrice)}</span>
                                                    )}
                                                </div>

                                                {/* discount */}
                                                <div className="flex items-center gap-1">
                                                    <Label className="text-muted-foreground text-xs">خصم</Label>
                                                    <Input
                                                        type="number"
                                                        min={0}
                                                        max={100}
                                                        value={line.discountPct}
                                                        onChange={(e) =>
                                                            updateLine(line.key, {
                                                                discountPct: Math.min(100, Math.max(0, Number(e.target.value) || 0)),
                                                            })
                                                        }
                                                        className="h-7 w-16 text-center"
                                                    />
                                                    <span className="text-muted-foreground text-xs">%</span>
                                                </div>

                                                <span className="text-sm font-semibold">{formatCurrency(lineTotal(line))}</span>
                                            </div>
                                        </div>
                                    ))}

                                    <Button type="button" variant="outline" size="sm" className="w-full" onClick={addManualLine}>
                                        <Plus className="size-4" /> سطر يدوي
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
