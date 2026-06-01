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
import { Minus, Plus, Search, ShoppingCart, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
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

const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;

const lineTotal = (line: CartLine) => round2(line.qty * line.unitPrice * (1 - line.discountPct / 100));

export default function ProductPos({ products, customers, paymentMethods, vatPct }: Props) {
    const { props } = usePage<SharedData>();
    const [search, setSearch] = useState('');
    const [cart, setCart] = useState<CartLine[]>([]);
    const [customerId, setCustomerId] = useState<string>('none');
    const [paymentMethodId, setPaymentMethodId] = useState<string>('none');
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (props.success) {
            toast.success(props.success as string);
        }
    }, [props.success]);

    const filteredProducts = useMemo(() => {
        const term = search.trim().toLowerCase();
        if (!term) return products;
        return products.filter((p) => p.name.toLowerCase().includes(term) || p.sku.toLowerCase().includes(term));
    }, [products, search]);

    const subtotal = useMemo(() => round2(cart.reduce((sum, l) => sum + lineTotal(l), 0)), [cart]);
    const vatAmount = useMemo(() => round2((subtotal * vatPct) / 100), [subtotal, vatPct]);
    const total = useMemo(() => round2(subtotal + vatAmount), [subtotal, vatAmount]);

    function addToCart(p: PosProduct) {
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
                    productId: p.id,
                    name: p.name,
                    sku: p.sku,
                    unitPrice: p.sellingPrice,
                    qty: 1,
                    discountPct: 0,
                    maxStock: p.currentStock,
                    unitName: p.unitName,
                },
            ];
        });
    }

    function updateLine(productId: number, patch: Partial<CartLine>) {
        setCart((prev) => prev.map((l) => (l.productId === productId ? { ...l, ...patch } : l)));
    }

    function changeQty(line: CartLine, delta: number) {
        const next = line.qty + delta;
        if (next < 1) return;
        if (next > line.maxStock) {
            toast.error(`الكمية القصوى المتاحة هي ${line.maxStock}`);
            return;
        }
        updateLine(line.productId, { qty: next });
    }

    function removeLine(productId: number) {
        setCart((prev) => prev.filter((l) => l.productId !== productId));
    }

    function submit(status: 'paid' | 'due') {
        if (cart.length === 0) {
            toast.error('أضف منتجاً واحداً على الأقل');
            return;
        }
        setSubmitting(true);
        setErrors({});
        router.post(
            product.store().url,
            {
                customer_id: customerId === 'none' ? null : Number(customerId),
                payment_method_id: paymentMethodId === 'none' ? null : Number(paymentMethodId),
                status,
                lines: cart.map((l) => ({
                    product_id: l.productId,
                    qty: l.qty,
                    unit_price: l.unitPrice,
                    discount_pct: l.discountPct,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setCart([]);
                    setCustomerId('none');
                    setPaymentMethodId('none');
                },
                onError: (e) => setErrors(e as Record<string, string>),
                onFinish: () => setSubmitting(false),
            },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="نقطة البيع — فاتورة منتجات" />
            <Toaster position="top-center" richColors />

            <div className="grid gap-4 p-4 lg:grid-cols-3">
                {/* Product picker */}
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Search className="size-5" /> المنتجات
                        </CardTitle>
                        <div className="relative mt-2">
                            <Search className="text-muted-foreground absolute top-1/2 right-3 size-4 -translate-y-1/2" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="ابحث بالاسم أو رقم الصنف..."
                                className="pr-9"
                            />
                        </div>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                            {filteredProducts.map((p) => (
                                <button
                                    key={p.id}
                                    type="button"
                                    onClick={() => addToCart(p)}
                                    disabled={p.currentStock <= 0}
                                    className="hover:border-primary hover:bg-accent flex flex-col gap-1 rounded-lg border p-3 text-right transition disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <span className="line-clamp-2 text-sm font-medium">{p.name}</span>
                                    <span className="text-muted-foreground text-xs">{p.sku}</span>
                                    <div className="mt-1 flex items-center justify-between">
                                        <span className="text-primary text-sm font-semibold">{formatCurrency(p.sellingPrice)}</span>
                                        <Badge variant={p.currentStock <= 0 ? 'destructive' : 'secondary'}>{p.currentStock}</Badge>
                                    </div>
                                </button>
                            ))}
                            {filteredProducts.length === 0 && (
                                <p className="text-muted-foreground col-span-full py-8 text-center text-sm">لا توجد منتجات مطابقة</p>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Cart & checkout */}
                <Card className="lg:col-span-1">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <ShoppingCart className="size-5" /> السلة
                            {cart.length > 0 && <Badge>{cart.length}</Badge>}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {errors.lines && <p className="bg-destructive/10 text-destructive rounded-md p-2 text-sm">{errors.lines}</p>}

                        <div className="space-y-3">
                            {cart.length === 0 && <p className="text-muted-foreground py-6 text-center text-sm">السلة فارغة — اختر منتجاً لإضافته</p>}
                            {cart.map((line) => (
                                <div key={line.productId} className="rounded-lg border p-2">
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-medium">{line.name}</p>
                                            <p className="text-muted-foreground text-xs">
                                                {formatCurrency(line.unitPrice)}
                                                {line.unitName ? ` / ${line.unitName}` : ''}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => removeLine(line.productId)}
                                            className="text-muted-foreground hover:text-destructive"
                                        >
                                            <Trash2 className="size-4" />
                                        </button>
                                    </div>
                                    <div className="mt-2 flex items-center justify-between gap-2">
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
                                            <Button type="button" size="icon" variant="outline" className="size-7" onClick={() => changeQty(line, 1)}>
                                                <Plus className="size-3" />
                                            </Button>
                                        </div>
                                        <div className="flex items-center gap-1">
                                            <Input
                                                type="number"
                                                min={0}
                                                max={100}
                                                value={line.discountPct}
                                                onChange={(e) =>
                                                    updateLine(line.productId, {
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
                        </div>

                        <Separator />

                        <div className="space-y-3">
                            <div>
                                <Label className="text-xs">العميل</Label>
                                <Select value={customerId} onValueChange={setCustomerId}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="عميل نقدي" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">عميل نقدي</SelectItem>
                                        {customers.map((c) => (
                                            <SelectItem key={c.id} value={String(c.id)}>
                                                {c.fullName} — {c.phone}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">طريقة الدفع</Label>
                                <Select value={paymentMethodId} onValueChange={setPaymentMethodId}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="اختر طريقة الدفع" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">بدون تحديد</SelectItem>
                                        {paymentMethods.map((m) => (
                                            <SelectItem key={m.id} value={String(m.id)}>
                                                {m.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <Separator />

                        <div className="space-y-1 text-sm">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">المجموع الفرعي</span>
                                <span>{formatCurrency(subtotal)}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">ضريبة القيمة المضافة ({vatPct}%)</span>
                                <span>{formatCurrency(vatAmount)}</span>
                            </div>
                            <div className="flex justify-between text-base font-bold">
                                <span>الإجمالي</span>
                                <span>{formatCurrency(total)}</span>
                            </div>
                        </div>

                        <div className="grid grid-cols-2 gap-2">
                            <Button type="button" disabled={submitting || cart.length === 0} onClick={() => submit('paid')}>
                                دفع
                            </Button>
                            <Button type="button" variant="outline" disabled={submitting || cart.length === 0} onClick={() => submit('due')}>
                                حفظ كآجل
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
