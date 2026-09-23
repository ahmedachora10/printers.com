import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/app-layout';
import { cn, formatCurrency } from '@/lib/utils';
import service from '@/routes/pos/service';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Calculator, Delete, Keyboard, Pin, Printer, Save } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

interface QuickService {
    id: number;
    name: string;
}

interface Props {
    services: QuickService[];
    defaultServiceId: number | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'فاتورة سريعة', href: service.quick().url }];

const KEYS = ['7', '8', '9', '4', '5', '6', '1', '2', '3', '.', '0'];

/** Digits and one decimal point, at most two decimals — what the till accepts. */
function sanitize(value: string): string {
    const [whole, ...rest] = value.replace(/[^\d.]/g, '').split('.');
    return rest.length ? `${whole}.${rest.join('').slice(0, 2)}` : whole;
}

/**
 * تاسك 118: فاتورة الزحمة — مبلغ ← Enter ← إرسال وطباعة ← تصفير، بلا ماوس.
 * الفاتورة معلّقة كأي فاتورة موظف، وتُرسل إلى service.store نفسه فلا حساب ثانٍ.
 */
export default function QuickInvoice({ services, defaultServiceId }: Props) {
    const { props } = usePage<{ success?: string }>();
    const [serviceId, setServiceId] = useState<number | null>(defaultServiceId ?? services[0]?.id ?? null);
    const [amount, setAmount] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const amountRef = useRef<HTMLInputElement>(null);
    const printFrame = useRef<HTMLIFrameElement>(null);

    const focusAmount = () => amountRef.current?.focus();

    useEffect(() => {
        if (props.success) toast.success(props.success);
    }, [props.success]);

    // F1–F8 تختار الخدمة بترتيبها على الشاشة، والتركيز يبقى في خانة المبلغ.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const match = /^F([1-8])$/.exec(e.key);
            const target = match ? services[Number(match[1]) - 1] : undefined;
            if (!target) return;
            e.preventDefault();
            setServiceId(target.id);
            focusAmount();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [services]);

    function press(key: string) {
        setAmount((a) => sanitize(key === 'back' ? a.slice(0, -1) : a + key));
        focusAmount();
    }

    function submit(print: boolean) {
        if (submitting) return;
        if (!serviceId) {
            toast.error('اختر خدمة');
            return;
        }
        if (!(Number(amount) > 0)) {
            toast.error('أدخل المبلغ');
            focusAmount();
            return;
        }

        setSubmitting(true);
        router.post(
            service.store().url,
            {
                status: 'due',
                quick: true,
                print,
                customer_id: null,
                lines: [{ branch_service_id: serviceId, qty: 1, unit_price: Number(amount), discount_pct: 0 }],
            },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: (page) => {
                    setAmount('');
                    // الافتراضية تعود بعد كل فاتورة؛ وبلا افتراضية تبقى آخر خدمة.
                    if (defaultServiceId) setServiceId(defaultServiceId);
                    const printId = page.props.printInvoiceId as number | null;
                    // مسار الطباعة القائم في إطارٍ خفيّ: يطبع نفسه عند التحميل،
                    // والصفحة لا تُغادَر ولا تحجبها نافذةٌ منبثقة.
                    if (printId && printFrame.current) printFrame.current.src = service.print(printId).url;
                },
                onError: (errors) => toast.error(Object.values(errors)[0] ?? 'تعذّر حفظ الفاتورة'),
                onFinish: () => {
                    setSubmitting(false);
                    focusAmount();
                },
            },
        );
    }

    function toggleDefault(id: number) {
        router.post(service.quick.default(id).url, {}, { preserveState: true, preserveScroll: true, onFinish: focusAmount });
    }

    const selected = services.find((s) => s.id === serviceId);
    // أزرار الشاشة لا تسرق التركيز من خانة المبلغ — لا بالنقر ولا بـTab.
    const keepFocus = { tabIndex: -1, onMouseDown: (e: React.MouseEvent) => e.preventDefault() };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="نقطة البيع — فاتورة سريعة" />
            <Toaster position="top-center" richColors />

            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    submit(true);
                }}
                className="grid gap-4 p-4 lg:grid-cols-3"
            >
                {/* Main — services + amount */}
                <div className="space-y-4 lg:col-span-2">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">الخدمات</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {services.length === 0 ? (
                                <p className="text-muted-foreground p-3 text-center text-sm">لا توجد خدمات بسعر القطعة متاحة للفاتورة السريعة في فرعك.</p>
                            ) : (
                                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                                    {services.map((s, i) => {
                                        const isDefault = defaultServiceId === s.id;
                                        return (
                                            <div key={s.id} className="relative">
                                                <button
                                                    type="button"
                                                    {...keepFocus}
                                                    onClick={() => setServiceId(s.id)}
                                                    className={cn(
                                                        'flex w-full flex-col items-start gap-1 rounded-lg border p-3 pe-9 text-right text-sm transition',
                                                        serviceId === s.id ? 'border-primary bg-primary/10 ring-primary ring-1' : 'hover:bg-accent',
                                                    )}
                                                >
                                                    <span className="line-clamp-2 font-medium">{s.name}</span>
                                                    <span className="text-muted-foreground text-xs" dir="ltr">
                                                        F{i + 1}
                                                    </span>
                                                </button>
                                                <button
                                                    type="button"
                                                    {...keepFocus}
                                                    onClick={() => toggleDefault(s.id)}
                                                    title={isDefault ? 'إلغاء الخدمة الافتراضية' : 'تعيين كخدمة افتراضية'}
                                                    aria-label={isDefault ? 'إلغاء الخدمة الافتراضية' : 'تعيين كخدمة افتراضية'}
                                                    aria-pressed={isDefault}
                                                    className="text-muted-foreground absolute end-1.5 top-1.5 rounded-md p-1.5 transition hover:text-amber-500"
                                                >
                                                    <Pin className={cn('size-3.5', isDefault && 'fill-amber-400 text-amber-400')} />
                                                </button>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Calculator className="size-4" /> المبلغ
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="mx-auto w-full max-w-sm space-y-3">
                            <div className="space-y-1">
                                <Label htmlFor="quick-amount" className="text-muted-foreground text-xs">
                                    المبلغ شامل الضريبة (ر.س)
                                </Label>
                                <Input
                                    id="quick-amount"
                                    ref={amountRef}
                                    autoFocus
                                    dir="ltr"
                                    inputMode="decimal"
                                    autoComplete="off"
                                    value={amount}
                                    onChange={(e) => setAmount(sanitize(e.target.value))}
                                    placeholder="0.00"
                                    className="h-14 text-center text-2xl font-bold tabular-nums"
                                />
                            </div>

                            <div dir="ltr" className="grid grid-cols-3 gap-2">
                                {KEYS.map((k) => (
                                    <Button key={k} type="button" variant="outline" {...keepFocus} onClick={() => press(k)} className="h-12 text-lg">
                                        {k}
                                    </Button>
                                ))}
                                <Button type="button" variant="outline" {...keepFocus} onClick={() => press('back')} className="h-12" aria-label="حذف">
                                    <Delete className="size-5" />
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Sidebar — status, summary, actions */}
                <div className="space-y-4 lg:col-span-1">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">حالة الفاتورة</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400">
                                تُحفظ الفاتورة كـ <span className="font-semibold">معلقة</span> ليراجعها المحاسب ويعتمد الدفع.
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">ملخص الفاتورة</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-muted-foreground">الخدمة</span>
                                <span className="font-medium">{selected?.name ?? '—'}</span>
                            </div>
                            <div className="flex items-center justify-between gap-2">
                                <span className="text-muted-foreground">العميل</span>
                                <span>عميل عابر</span>
                            </div>
                            <Separator />
                            <div className="flex items-center justify-between gap-2 text-base font-bold">
                                <span>الإجمالي</span>
                                <span>{formatCurrency(Number(amount) || 0)}</span>
                            </div>
                        </CardContent>
                    </Card>

                    {/* Actions */}
                    <div className="space-y-2">
                        <Button type="button" className="w-full" disabled={submitting} onClick={() => submit(false)}>
                            <Save className="size-4" /> إرسال الفاتورة
                        </Button>
                        <Button type="submit" variant="outline" className="w-full" disabled={submitting}>
                            <Printer className="size-4" /> إرسال وطباعة الفاتورة
                        </Button>
                    </div>

                    <div className="text-muted-foreground flex items-start gap-2 text-xs">
                        <Keyboard className="mt-0.5 size-4 shrink-0" />
                        <span>
                            <span dir="ltr">Enter</span> = إرسال وطباعة · <span dir="ltr">F1–F8</span> لاختيار الخدمة · الدبوس يثبّت الخدمة الافتراضية
                        </span>
                    </div>
                </div>
            </form>

            <iframe
                ref={printFrame}
                title="طباعة الفاتورة"
                // لا display:none — بعض المتصفحات تطبع الإطار المخفيّ صفحةً فارغة.
                className="pointer-events-none fixed bottom-0 left-0 size-px opacity-0"
                onLoad={() => {
                    const win = printFrame.current?.contentWindow;
                    if (win) win.onafterprint = focusAmount;
                }}
            />
        </AppLayout>
    );
}

