import { noteExamplesPlaceholder } from '@/components/branch-services/note-examples-field';
import InvoiceCustomerFields, { type InvoiceCustomerErrors, type InvoiceCustomerFormData } from '@/components/invoices/invoice-customer-fields';
import { ReceiptField } from '@/components/invoices/receipt-field';
import { LINE_HINT_CLASS, LineChip, LineField, LineHint, LineReadout, LineSection, PosCartTable } from '@/components/pos/cart-table';
import { PosStickyTotalBar } from '@/components/pos/sticky-total-bar';
import { AsyncCombobox, type AsyncOption } from '@/components/ui/async-combobox';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Toaster } from '@/components/ui/sonner';
import AppLayout from '@/layouts/app-layout';
import { invoiceTotals } from '@/lib/invoice';
import { cn, formatCurrency, formatDateTimeNumeric, formatQty } from '@/lib/utils';
import serviceInvoice from '@/routes/invoices/service';
import service from '@/routes/pos/service';
import { type BreadcrumbItem, type SharedData } from '@/types';
import {
    type EditServiceInvoice,
    type LineAgentCommissionType,
    type PosAgent,
    type PosCustomer,
    type PosLoyalty,
    type PosPaymentMethod,
    type PosService,
    type ServiceCartLine,
} from '@/types/pos';
import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Award, BadgePercent, CalendarClock, Info, Package, Printer, Ruler, Save, Search, StickyNote, Tag, X } from 'lucide-react';
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

interface Props {
    services: PosService[];
    agents: PosAgent[];
    paymentMethods: PosPaymentMethod[];
    vatPct: number;
    loyalty: PosLoyalty;
    /** Present only when the owning employee re-opens a DUE invoice to edit it. */
    invoice?: EditServiceInvoice;
}

type InvoiceStatus = 'paid' | 'due';

interface AppliedCoupon {
    code: string;
    type: 'percentage' | 'fixed';
    value: number;
}

const round2 = (n: number) => Math.round((n + Number.EPSILON) * 100) / 100;

/** اليوم بصيغة YYYY-MM-DD محلياً — أدنى موعد تسليم يقبله الخادم. */
function todayIso(): string {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** يفكّ «YYYY-MM-DD HH:MM» المخزَّن إلى جزأي المنتقي. */
function splitDeliveryAt(value: string | null | undefined): { date: string; time: string } {
    if (!value) return { date: '', time: '' };
    const [date = '', time = ''] = value.trim().split(' ');
    return { date, time: time.slice(0, 5) };
}

/** Area of one piece in m² from the entered cm dimensions. */
const lineAreaSqm = (line: ServiceCartLine) => ((line.widthCm ?? 0) / 100) * ((line.heightCm ?? 0) / 100);

/**
 * الوحدات المُفوترة في السطر: عدد القطع لخدمة بالوحدة، ومجموع الأمتار المربعة
 * (الكمية × مساحة القطعة) لخدمة بالمتر المربع — إذ سعرها سعرُ متر لا سعرَ قطعة.
 */
const lineUnits = (line: ServiceCartLine) => (line.pricingType === 'sqm' ? line.qty * lineAreaSqm(line) : line.qty);

/** إجمالي السطر — نفس صيغة الخادم: الوحدات × السعر، ويُقرَّب المجموع وحده. */
const lineTotal = (line: ServiceCartLine) => round2(lineUnits(line) * line.unitPrice * (1 - line.discountPct / 100));

/** سعر القطعة الواحدة لسطر بالمتر = مساحتها × سعر المتر — عرضٌ للاطلاع فقط. */
const linePiecePrice = (line: ServiceCartLine) => round2(lineAreaSqm(line) * line.unitPrice);

/**
 * هل تجاوز السطر سقف سعر الخدمة؟ السقف يقيس السعر المكتوب نفسه في الحالتين —
 * سعر الوحدة أو سعر المتر — لا إجمالي السطر مهما كبر المقاس. يُقرَّب كما يقرّب
 * الخادم: منزلتان.
 */
const isLineOverPriceCap = (line: ServiceCartLine): boolean =>
    line.maxSellingPrice !== null && line.maxSellingPrice > 0 && round2(line.unitPrice) > round2(line.maxSellingPrice);

/**
 * تكلفة خامات السطر كاملة — المبلغ للوحدة مضروباً في **الوحدات المُفوترة** كما
 * يفعل الخادم (تاسك 63): عدد القطع لخدمة بالوحدة، والأمتار المربعة لخدمة بالمتر.
 * صفر حين يكون المفتاح مُطفأً حتى لو حملت الخدمة قيمة افتراضية.
 */
const lineMaterialsTotal = (line: ServiceCartLine) =>
    line.hasMaterials ? round2(round2(Math.max(0, line.materialsCost)) * lineUnits(line)) : 0;

/**
 * تكلفة خامات **الوحدة الواحدة** في السطر، صافيةً كما عُرّفت. صفر حين لا خامات
 * على السطر. هذا هو الرقم الذي يُخصم من أساس العمولة، ولم يتغيّر.
 */
const lineMaterialsUnitCost = (line: ServiceCartLine) => (line.hasMaterials ? round2(Math.max(0, line.materialsCost)) : 0);

/**
 * تكلفة الخامة **شاملةً الضريبة** — الطرف الذي يُقارَن به السعر في أرضية التاسك
 * 65، لأن السعر المكتوب شاملٌ لها منذ التاسك 37. خامةٌ بـ20 = «اكتب 23.00 فأكثر».
 */
const lineMaterialsUnitCostGross = (line: ServiceCartLine, vatPct: number) =>
    round2(lineMaterialsUnitCost(line) * (1 + vatPct / 100));

/**
 * أرضية سعر السطر (تاسك 65) — أعلى الحدَّين، وكلاهما **شامل الضريبة**: أقل سعر
 * معرَّف على الخدمة (تاسك 64، وهو سعرٌ فيُقرأ شاملاً كما كُتب) وتكلفة خامات
 * الوحدة مرفوعةً بالضريبة. صفر يعني بلا أرضية.
 */
const linePriceFloor = (line: ServiceCartLine, vatPct: number) =>
    Math.max(line.minSellingPrice ?? 0, lineMaterialsUnitCostGross(line, vatPct));

/**
 * السعر المقبوض فعلاً على الوحدة: بعد خصم السطر وشاملاً الضريبة — نفس ما يقيسه
 * الخادم. يخالف السقف في الخصم لا في الضريبة: السقف يقيس المكتوب قبل الخصم
 * ليحمي العميل، والأرضية تقيس ما بعد الخصم لتحمي المركز من خصمٍ ينزل بالسعر
 * تحت التكلفة.
 */
const lineEffectiveUnitPrice = (line: ServiceCartLine) => round2(line.unitPrice * (1 - line.discountPct / 100));

/** هل نزل السطر تحت أرضيته؟ */
const isLineUnderPriceFloor = (line: ServiceCartLine, vatPct: number): boolean => {
    const floor = linePriceFloor(line, vatPct);

    return floor > 0 && lineEffectiveUnitPrice(line) < round2(floor);
};

/**
 * The line's commission-owner share — mirrors the server formulas. Only the
 * percentage case divides VAT out of its base; a fixed or per-sqm rate is an
 * agreed SAR figure with no tax inside it to strip.
 *
 * تاسك 69: and only the percentage case bears the line's materials cost, when
 * this branch's terms with the owner say so. The owner has to be passed in
 * because that flag lives on the agent, not on the cart line — and the preview
 * must land on the server's figure or the cashier reads 8.70 while 7.70 saves.
 */
function lineAgentCommission(line: ServiceCartLine, vatPct: number, agents: PosAgent[]): number {
    if (!line.agentId || !line.agentCommissionType) return 0;
    switch (line.agentCommissionType) {
        case 'percentage': {
            const netBeforeVat = round2(lineTotal(line) / (1 + vatPct / 100));
            const deducts = agents.find((a) => a.id === line.agentId)?.deductMaterials ?? false;
            // Materials dearer than the line leave no commission, never a
            // negative one — the same clamp the employee's base uses.
            const base = deducts ? Math.max(0, round2(netBeforeVat - lineMaterialsTotal(line))) : netBeforeVat;

            return round2((base * line.agentCommissionValue) / 100);
        }
        case 'fixed':
            return round2(line.agentCommissionValue * line.qty);
        case 'per_sqm':
            return round2(line.qty * lineAreaSqm(line) * line.agentCommissionValue);
    }
}

export default function ServicePos({ services, agents, paymentMethods, vatPct, loyalty, invoice }: Props) {
    const { props } = usePage<SharedData>();
    // Employees may only raise DUE (معلق) invoices for an accountant to review;
    // the paid/due toggle is hidden for them and the status is locked to 'due'.
    const isEmployee = props.auth.role === 'employee';
    // تاسك 54: تكلفة الخامات تُخصم من عمولة الموظف، فيقرأها ولا يكتبها. القيمة
    // تأتي من تعريف الخدمة، والقيد الحقيقي على الخادم (CalculateServiceInvoiceAction).
    const canEditMaterials = !isEmployee;
    /**
     * حدّا سعر البيع يلزمان الموظف وحده — المحاسب ومدير الفرع يبيعان بما يريان،
     * تماماً كما يقرّر الخادم. الرسالة تظهر تحت حقل السعر ويمنع الحفظ معها.
     *
     * والأرضية (تاسك 65) تسمّي الحدّ الذي لُمس — تكلفة الخامات أو أقل سعر — وإلا
     * بحث الموظف عن رقمٍ لا يراه في أي شاشة.
     */
    const priceBoundError = (line: ServiceCartLine): string | null => {
        if (!isEmployee) return null;

        if (isLineOverPriceCap(line)) {
            const cap = formatCurrency(line.maxSellingPrice ?? 0);

            return line.pricingType === 'sqm' ? `الحد الأعلى ${cap} للمتر` : `الحد الأعلى ${cap}`;
        }

        if (isLineUnderPriceFloor(line, vatPct)) {
            const floor = linePriceFloor(line, vatPct);
            const gross = lineMaterialsUnitCostGross(line, vatPct);
            // طرف الخامات يذكر أصله الصافي أيضاً، وإلا بحث الموظف عن الـ23.00
            // في شاشةٍ كُتب فيها 20.00.
            const reason =
                gross > (line.minSellingPrice ?? 0)
                    ? `تكلفة الخامات ${formatCurrency(lineMaterialsUnitCost(line))} + ضريبة`
                    : 'أقل سعر';
            const unit = line.pricingType === 'sqm' ? ' للمتر' : '';

            return `${reason} — اكتب ${formatCurrency(floor)}${unit} فأكثر شاملة الضريبة، والمكتوب ${formatCurrency(
                lineEffectiveUnitPrice(line),
            )}${unit}`;
        }

        return null;
    };
    const isEditing = !!invoice;
    const lineSeq = useRef(0);
    const [search, setSearch] = useState('');
    const [searchFocused, setSearchFocused] = useState(false);
    // Seed the cart from the invoice being edited, minting a stable key per line.
    const [cart, setCart] = useState<ServiceCartLine[]>(() =>
        (invoice?.lines ?? []).map((l) => {
            lineSeq.current += 1;
            return {
                key: `e-${l.branchServiceId}-${lineSeq.current}`,
                branchServiceId: l.branchServiceId,
                name: l.name,
                notes: l.notes ?? '',
                noteExamples: l.noteExamples ?? [],
                unitPrice: l.unitPrice,
                qty: l.qty,
                discountPct: l.discountPct,
                maxDiscountPct: l.maxDiscountPct,
                maxSellingPrice: l.maxSellingPrice ?? null,
                minSellingPrice: l.minSellingPrice ?? null,
                baseCommissionPct: l.baseCommissionPct,
                isTahazir: l.isTahazir,
                hasMaterials: l.hasMaterials ?? false,
                materialsCost: l.materialsCost ?? 0,
                // خامات المخزون تُقرأ من الخدمة لا من لقطة السطر: لم تُحفظ عليه،
                // والتحذير عن الرصيد شأنُ اليوم لا شأنُ يوم الفوترة.
                materials: services.find((s) => s.id === l.branchServiceId)?.materials ?? [],
                isManual: false,
                pricingType: l.pricingType ?? 'unit',
                pricePerSqm: l.pricePerSqm ?? 0,
                agentCommissionPerSqm: l.agentCommissionPerSqm ?? 0,
                widthCm: l.widthCm,
                heightCm: l.heightCm,
                agentId: l.agentId,
                agentCommissionType: l.agentCommissionType,
                agentCommissionValue: l.agentCommissionValue ?? 0,
            };
        }),
    );
    // The chosen customer is held in full (fetched on demand) rather than looked up
    // from a preloaded list, so 10k+ customers never ship to the browser.
    const [selectedCustomer, setSelectedCustomer] = useState<PosCustomer | null>(invoice?.customer ?? null);
    // Customer details, editable while re-editing a DUE invoice. Saved on the same
    // endpoint the accountant's review queue uses; the server decides what this
    // employee may rewrite on a record shared by all of that customer's invoices.
    const [customerEdit, setCustomerEdit] = useState<InvoiceCustomerFormData>({
        full_name: invoice?.customer?.fullName ?? '',
        phone: invoice?.customer?.phone ?? '',
        tax_number: invoice?.customer?.taxNumber ?? '',
    });
    const [customerErrors, setCustomerErrors] = useState<InvoiceCustomerErrors>({});
    const [savingCustomer, setSavingCustomer] = useState(false);
    const customerId = selectedCustomer ? String(selectedCustomer.id) : 'none';
    // A service invoice may carry several agents (shared rebate). There is no
    // longer a picker on this screen — agents come from the customer's own link
    // (or from the invoice being edited) and still drive discount/rebate.
    const [agentIds, setAgentIds] = useState<number[]>(invoice?.agentIds ?? []);
    const [walkinName, setWalkinName] = useState('');
    const [walkinPhone, setWalkinPhone] = useState('');
    const [walkinTaxNumber, setWalkinTaxNumber] = useState('');
    const [status, setStatus] = useState<InvoiceStatus>(isEmployee ? 'due' : 'paid');
    const [paymentMethodId, setPaymentMethodId] = useState<number | null>(invoice?.paymentMethodId ?? null);
    const [receipt, setReceipt] = useState<File | null>(null);
    const [couponCode, setCouponCode] = useState(invoice?.coupon?.code ?? '');
    const [appliedCoupon, setAppliedCoupon] = useState<AppliedCoupon | null>(
        invoice?.coupon ? { code: invoice.coupon.code, type: invoice.coupon.type, value: invoice.coupon.value } : null,
    );
    const [couponLoading, setCouponLoading] = useState(false);
    const [redeemPoints, setRedeemPoints] = useState(invoice?.pointsRedeemed ? String(invoice.pointsRedeemed) : '');
    // Remark about the whole order, printed under the lines table — distinct
    // from the per-line detail edited inside each cart row.
    const [notes, setNotes] = useState(invoice?.notes ?? '');
    // موعد تسليم العمل — يُلتقط تاريخاً ووقتاً منفصلين ويُرسل «YYYY-MM-DD HH:MM».
    const [deliveryDate, setDeliveryDate] = useState(() => splitDeliveryAt(invoice?.deliveryAt).date);
    const [deliveryTime, setDeliveryTime] = useState(() => splitDeliveryAt(invoice?.deliveryAt).time);
    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (props.success) {
            toast.success(props.success as string);
        }
    }, [props.success]);

    // Auto-fill the agent from the chosen customer's link and clear any pending
    // redemption when the customer changes — but not on the initial mount of an
    // edit, where both are already seeded from the invoice.
    const skipCustomerEffect = useRef(isEditing);
    useEffect(() => {
        if (skipCustomerEffect.current) {
            skipCustomerEffect.current = false;
            return;
        }
        setRedeemPoints('');
        setAgentIds(selectedCustomer?.agentId ? [selectedCustomer.agentId] : []);
    }, [selectedCustomer]);

    const fetchCustomers = useCallback(fetchCustomerOptions, []);

    const filteredServices = useMemo(() => {
        const term = search.trim().toLowerCase();
        if (!term) return [];
        return services.filter((s) => s.name.toLowerCase().includes(term)).slice(0, 8);
    }, [services, search]);

    const subtotal = useMemo(() => round2(cart.reduce((sum, l) => sum + lineTotal(l), 0)), [cart]);
    // إجمالي تكلفة الخامات — داخلي، لا يمسّ ما يدفعه العميل.
    // نقص خامات المخزون في السلة كلها، مجموعاً حسب المنتج لا حسب السطر: خامةٌ
    // تستهلكها خدمتان في الفاتورة نفسها لا يُكشف عجزها إلا بعد جمعهما. إرشاديٌّ
    // محض — الرصيد لقطةٌ وقتَ فتح الشاشة، والفحص المُلزِم عند الاعتماد على الخادم.
    const materialsShortfall = useMemo(() => {
        const demand = new Map<number, { name: string; unitName: string | null; required: number; available: number }>();

        for (const line of cart) {
            const units = lineUnits(line);
            if (units <= 0) continue;

            for (const material of line.materials ?? []) {
                const row = demand.get(material.productId) ?? {
                    name: material.name,
                    unitName: material.unitName,
                    required: 0,
                    available: material.availableStock,
                };
                row.required = round2(row.required + round2(material.qtyPerUnit * units * (1 + material.wastePct / 100)));
                demand.set(material.productId, row);
            }
        }

        return [...demand.values()].filter((row) => row.required > row.available);
    }, [cart]);

    const materialsTotal = useMemo(() => round2(cart.reduce((sum, l) => sum + lineMaterialsTotal(l), 0)), [cart]);
    const selectedAgents = useMemo(() => agents.filter((a) => agentIds.includes(a.id)), [agentIds, agents]);
    const selectedPaymentMethod = useMemo(() => paymentMethods.find((m) => m.id === paymentMethodId) ?? null, [paymentMethods, paymentMethodId]);
    const requiresReceipt = selectedPaymentMethod?.requiresAttachment ?? false;

    // ما يُرسل للخادم: التاريخ وحده لا يكفي موعداً، فيُفترض معه منتصف النهار.
    const deliveryAt = useMemo(() => (deliveryDate ? `${deliveryDate} ${deliveryTime || '12:00'}` : null), [deliveryDate, deliveryTime]);

    // Loyalty benefits apply only to an eligible customer with no agent on the
    // invoice. Pipeline mirrors the server: subtotal → tier → coupon → agent → points.
    const loyaltyOn = loyalty.active && !!selectedCustomer?.loyaltyEligible && agentIds.length === 0;

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
    // Discount-mode agents stack: each rate applied to the post-coupon base, then
    // summed and capped at that base (mirrors the server).
    const agentDiscount = useMemo(
        () =>
            round2(
                Math.min(
                    selectedAgents
                        .filter((a) => a.discountMode === 'discount')
                        .reduce((sum, a) => sum + (a.discountType === 'fixed' ? a.rate : (afterCoupon * a.rate) / 100), 0),
                    afterCoupon,
                ),
            ),
        [selectedAgents, afterCoupon],
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
    // Every commission is earned on the value net of VAT — mirrors the server's
    // netBeforeVat.
    const netBeforeVat = useMemo(() => round2(total / (1 + vatPct / 100)), [total, vatPct]);
    const vatAmount = useMemo(() => round2(total - netBeforeVat), [total, netBeforeVat]);
    // Employee commission is earned on that net value, after every invoice-level
    // discount (tier, coupon, agent discount, points) and after the line's own
    // materials cost. Mirrors the server line by line: each line takes its share
    // of netBeforeVat proportionally to its gross subtotal, subtracts its
    // materials (never scaled — a real SAR cost, clamped so commission can't go
    // negative), then applies the rate. Stays an estimate — it uses the service
    // base rate, not the employee's own rate.
    const commission = useMemo(() => {
        if (subtotal <= 0) return 0;
        const ratio = netBeforeVat / subtotal;

        return round2(
            cart.reduce((sum, l) => {
                const lineNet = round2(lineTotal(l) * ratio);
                const materials = Math.min(lineMaterialsTotal(l), lineNet);
                return sum + round2((round2(lineNet - materials) * l.baseCommissionPct) / 100);
            }, 0),
        );
    }, [cart, netBeforeVat, subtotal]);
    // تفكيك ما يُعرض للكاشير: مجموع فرعي وخصومات صافية من الضريبة يجمعها العمود
    // مع الضريبة فيساوي الإجمالي بالقرش — نفس اشتقاق شاشة الفاتورة والطباعة.
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
    // Each rebate-mode agent earns independently on the net-of-VAT value; the
    // preview shows their combined rebate.
    const agentRebate = useMemo(
        () =>
            round2(
                selectedAgents
                    .filter((a) => a.discountMode === 'rebate')
                    .reduce((sum, a) => sum + Math.min(a.discountType === 'fixed' ? a.rate : (netBeforeVat * a.rate) / 100, netBeforeVat), 0),
            ),
        [selectedAgents, netBeforeVat],
    );
    // Per-line commission owners' shares — informational: paid to the agents
    // later, never deducted from the customer's total.
    const lineAgentsCommission = useMemo(
        () => round2(cart.reduce((sum, l) => sum + lineAgentCommission(l, vatPct, agents), 0)),
        [cart, vatPct, agents],
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
                    notes: '',
                    noteExamples: s.noteExamples ?? [],
                    // سطر المتر يبدأ بسعر متر الخدمة — قابلاً للتعديل؛ وغيره بصفر.
                    unitPrice: s.pricingType === 'sqm' ? s.pricePerSqm : 0,
                    qty: 1,
                    discountPct: 0,
                    maxDiscountPct: s.maxDiscountPct,
                    maxSellingPrice: s.maxSellingPrice ?? null,
                    minSellingPrice: s.minSellingPrice ?? null,
                    baseCommissionPct: s.baseCommissionPct,
                    isTahazir: s.isTahazir,
                    hasMaterials: s.hasMaterials,
                    materialsCost: s.materialsCost,
                    materials: s.materials ?? [],
                    isManual: false,
                    pricingType: s.pricingType,
                    pricePerSqm: s.pricePerSqm,
                    agentCommissionPerSqm: s.agentCommissionPerSqm,
                    widthCm: null,
                    heightCm: null,
                    agentId: null,
                    agentCommissionType: null,
                    agentCommissionValue: 0,
                },
            ];
        });
        setSearch('');
    }

    /** Pick a customer and re-seed the editable details from that record. */
    function pickCustomer(customer: PosCustomer | null) {
        setSelectedCustomer(customer);
        setCustomerEdit({
            full_name: customer?.fullName ?? '',
            phone: customer?.phone ?? '',
            tax_number: customer?.taxNumber ?? '',
        });
        setCustomerErrors({});
    }

    // Nothing to submit until one of the three fields differs from the record.
    const customerDetailsChanged =
        selectedCustomer !== null &&
        (customerEdit.full_name.trim() !== selectedCustomer.fullName ||
            customerEdit.phone.trim() !== selectedCustomer.phone ||
            customerEdit.tax_number.trim() !== (selectedCustomer.taxNumber ?? ''));

    function saveCustomer() {
        if (!invoice || !selectedCustomer) return;
        const payload = {
            full_name: customerEdit.full_name.trim(),
            phone: customerEdit.phone.trim(),
            tax_number: customerEdit.tax_number.trim(),
        };
        setSavingCustomer(true);
        setCustomerErrors({});
        router.patch(serviceInvoice.updateCustomer(invoice.id).url, payload, {
            preserveScroll: true,
            preserveState: true,
            onError: (e) => setCustomerErrors({ full_name: e.full_name, phone: e.phone, tax_number: e.tax_number }),
            onSuccess: () => {
                setSelectedCustomer((c) =>
                    c ? { ...c, fullName: payload.full_name, phone: payload.phone, taxNumber: payload.tax_number || null } : c,
                );
                toast.success('تم حفظ بيانات العميل.');
            },
            onFinish: () => setSavingCustomer(false),
        });
    }

    function addManualLine() {
        lineSeq.current += 1;
        setCart((prev) => [
            ...prev,
            {
                key: `m-${lineSeq.current}`,
                branchServiceId: null,
                name: '',
                notes: '',
                noteExamples: [],
                unitPrice: 0,
                qty: 1,
                discountPct: 0,
                maxDiscountPct: 0,
                maxSellingPrice: null,
                minSellingPrice: null,
                baseCommissionPct: 0,
                isTahazir: false,
                hasMaterials: false,
                materialsCost: 0,
                materials: [],
                isManual: true,
                pricingType: 'unit',
                pricePerSqm: 0,
                agentCommissionPerSqm: 0,
                widthCm: null,
                heightCm: null,
                agentId: null,
                agentCommissionType: null,
                agentCommissionValue: 0,
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
            noteExamples: s.noteExamples ?? [],
            maxDiscountPct: s.maxDiscountPct,
            maxSellingPrice: s.maxSellingPrice ?? null,
            minSellingPrice: s.minSellingPrice ?? null,
            baseCommissionPct: s.baseCommissionPct,
            isTahazir: s.isTahazir,
            // الخامات تُعاد تعبئتها من الخدمة الجديدة — الخدمة تغيّرت فتغيّرت موادها.
            hasMaterials: s.hasMaterials,
            materialsCost: s.materialsCost,
            materials: s.materials ?? [],
            discountPct: Math.min(cap, line.discountPct),
            pricingType: s.pricingType,
            pricePerSqm: s.pricePerSqm,
            agentCommissionPerSqm: s.agentCommissionPerSqm,
            // سعر سطر المتر هو سعر متر الخدمة؛ والمقاس يُفرَّغ ليُدخَل من جديد.
            ...(s.pricingType === 'sqm' ? { unitPrice: s.pricePerSqm, widthCm: null, heightCm: null } : { widthCm: null, heightCm: null }),
            // A per_sqm commission type no longer applies on a unit service.
            ...(s.pricingType !== 'sqm' && line.agentCommissionType === 'per_sqm'
                ? { agentCommissionType: 'percentage' as LineAgentCommissionType, agentCommissionValue: 0 }
                : {}),
        });
    }

    /** Attach/replace a line's commission owner, prefilling their saved terms. */
    function setLineAgent(line: ServiceCartLine, agentId: number | null) {
        if (agentId === null) {
            updateLine(line.key, { agentId: null, agentCommissionType: null, agentCommissionValue: 0 });
            return;
        }
        const agent = agents.find((a) => a.id === agentId);
        if (!agent) return;
        // Sqm services default to the per-sqm rate from the service settings;
        // otherwise the agent's saved profile terms are the suggestion. Both stay
        // editable — the server recomputes the amount from what is submitted.
        if (line.pricingType === 'sqm' && line.agentCommissionPerSqm > 0) {
            updateLine(line.key, { agentId, agentCommissionType: 'per_sqm', agentCommissionValue: line.agentCommissionPerSqm });
        } else {
            updateLine(line.key, {
                agentId,
                agentCommissionType: agent.discountType === 'fixed' ? 'fixed' : 'percentage',
                agentCommissionValue: agent.rate,
            });
        }
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
        setSelectedCustomer(null);
        setAgentIds([]);
        setWalkinName('');
        setWalkinPhone('');
        setWalkinTaxNumber('');
        setPaymentMethodId(null);
        setReceipt(null);
        setStatus(isEmployee ? 'due' : 'paid');
        setRedeemPoints('');
        setNotes('');
        setDeliveryDate('');
        setDeliveryTime('');
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
        if (cart.some((l) => l.pricingType === 'sqm' && (!l.widthCm || !l.heightCm))) {
            toast.error('أدخل العرض والطول لخدمات المتر المربع');
            return;
        }
        const overCap = isEmployee ? cart.find(isLineOverPriceCap) : undefined;
        if (overCap) {
            toast.error(
                `سعر "${overCap.name}" يتجاوز الحد الأعلى المسموح (${formatCurrency(overCap.maxSellingPrice ?? 0)}${
                    overCap.pricingType === 'sqm' ? ' للمتر' : ''
                })`,
            );
            return;
        }
        // الأرضية (تاسك 65) — أعلى الحدَّين: تكلفة الخامات وأقل سعر معرَّف.
        const underFloor = isEmployee ? cart.find((l) => isLineUnderPriceFloor(l, vatPct)) : undefined;
        if (underFloor) {
            const reason =
                lineMaterialsUnitCostGross(underFloor, vatPct) > (underFloor.minSellingPrice ?? 0) ? 'تكلفة الخامات' : 'أقل سعر للبيع';
            toast.error(
                `سعر "${underFloor.name}" بعد الخصم يقلّ عن ${reason} (${formatCurrency(linePriceFloor(underFloor, vatPct))}${
                    underFloor.pricingType === 'sqm' ? ' للمتر' : ''
                } شاملة الضريبة)`,
            );
            return;
        }
        // الموظف يحفظ فاتورته المعلّقة بلا طريقة دفع؛ من يعتمدها هو من يلزمه ذلك.
        if (!isEmployee && !paymentMethodId) {
            toast.error('اختر طريقة الدفع قبل حفظ الفاتورة');
            return;
        }
        // A bank-transfer method needs a receipt — unless editing an invoice that
        // already carries one and keeps the same method.
        if (requiresReceipt && !receipt && !(isEditing && invoice?.hasReceipt && paymentMethodId === invoice.paymentMethodId)) {
            toast.error('يجب إرفاق إيصال التحويل لطريقة الدفع المحددة');
            return;
        }
        setSubmitting(true);
        setErrors({});

        const payload: Record<string, unknown> = {
            customer_id: customerId === 'none' ? null : Number(customerId),
            agent_ids: agentIds,
            walkin_name: customerId === 'none' ? walkinName.trim() || null : null,
            walkin_phone: customerId === 'none' ? walkinPhone.trim() || null : null,
            walkin_tax_number: customerId === 'none' ? walkinTaxNumber.trim() || null : null,
            coupon_code: appliedCoupon?.code ?? null,
            redeem_points: loyaltyOn && Number(redeemPoints) > 0 ? Number(redeemPoints) : null,
            payment_method_id: paymentMethodId,
            receipt,
            notes: notes.trim() || null,
            delivery_at: deliveryAt,
            lines: cart.map((l) => ({
                branch_service_id: l.branchServiceId,
                notes: l.notes.trim() || null,
                qty: l.qty,
                unit_price: l.unitPrice,
                discount_pct: l.discountPct,
                width_cm: l.pricingType === 'sqm' ? l.widthCm : null,
                height_cm: l.pricingType === 'sqm' ? l.heightCm : null,
                has_materials: l.hasMaterials,
                materials_cost: l.hasMaterials ? l.materialsCost : 0,
                agent_id: l.agentId,
                agent_commission_type: l.agentId ? l.agentCommissionType : null,
                agent_commission_value: l.agentId ? l.agentCommissionValue : null,
            })),
        };

        // Editing PUTs (spoofed) back onto the same invoice and returns to its
        // viewer; creating POSTs a new invoice and clears the form to start over.
        const options = {
            forceFormData: true,
            preserveScroll: true,
            onError: (e: Record<string, string>) => setErrors(e),
            onFinish: () => setSubmitting(false),
        };

        if (isEditing && invoice) {
            router.post(service.update(invoice.id).url, { ...payload, _method: 'put' }, options);
        } else {
            router.post(service.store().url, { ...payload, status, print }, { ...options, onSuccess: () => resetForm() });
        }
    }

    // سطر واحد خارج حدَّيه يكفي لتعطيل الحفظ — لا تُرسَل فاتورة يرفضها الخادم.
    const hasPriceCapViolation = isEmployee && cart.some((l) => isLineOverPriceCap(l) || isLineUnderPriceFloor(l, vatPct));

    const showResults = searchFocused && search.trim() !== '';

    const breadcrumbs: BreadcrumbItem[] = isEditing
        ? [
              { title: 'الفواتير', href: '/invoices' },
              { title: `تعديل ${invoice!.invoiceNumber}`, href: service.edit(invoice!.id).url },
          ]
        : [
              { title: 'نقطة البيع', href: service.create().url },
              { title: 'فاتورة خدمة', href: service.create().url },
          ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={isEditing ? `تعديل فاتورة ${invoice!.invoiceNumber}` : 'نقطة البيع — فاتورة خدمة'} />
            <Toaster position="top-center" richColors />

            {/* تاسك 70: مراجعٌ يصحّح فاتورة موظف قبل اعتمادها — يُقال له صراحةً
                إنّ الفاتورة ليست له وإنّ التعديل مُسجّل. */}
            {isEditing && invoice!.isOwn === false && (
                <div className="px-4 pt-4">
                    <div className="flex items-start gap-2 rounded-md border border-sky-500/40 bg-sky-500/10 p-3 text-sm text-sky-700 dark:text-sky-400">
                        <Info className="mt-0.5 size-4 shrink-0" />
                        <span>
                            تعدّل فاتورة الموظف <span className="font-semibold">{invoice!.employeeName ?? '—'}</span> — تبقى الفاتورة معلّقة بعد
                            الحفظ، وعمولتها تُحتسب له هو، ويُسجَّل التعديل في سجلّ النشاط.
                        </span>
                    </div>
                </div>
            )}

            {/* pb-24 below lg clears the fixed total bar at the bottom. */}
            <div className="grid gap-4 p-4 pb-24 lg:grid-cols-3 lg:pb-4">
                {/* Sidebar — customer, status, coupon, totals, payment, actions.
                    Below lg it follows the cart, so the employee starts on the
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
                                onChange={(_v, option) => pickCustomer(option?.data ?? null)}
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
                                    <Input
                                        value={walkinTaxNumber}
                                        onChange={(e) => setWalkinTaxNumber(e.target.value.replace(/[^0-9]/g, ''))}
                                        placeholder="الرقم الضريبي (اختياري — 15 رقماً)"
                                        inputMode="numeric"
                                        maxLength={15}
                                    />
                                    {errors.walkin_tax_number && <p className="text-destructive text-xs">{errors.walkin_tax_number}</p>}
                                </>
                            )}

                            {/* Editing a DUE invoice: let the employee correct the registered
                                customer's details without leaving the screen. */}
                            {isEditing && selectedCustomer && (
                                <div className="space-y-2 border-t pt-3">
                                    <Label className="text-muted-foreground text-xs">بيانات العميل</Label>
                                    <InvoiceCustomerFields
                                        idPrefix="pos-customer"
                                        data={customerEdit}
                                        onChange={(field, value) =>
                                            setCustomerEdit((prev) => ({
                                                ...prev,
                                                [field]: field === 'tax_number' ? value.replace(/[^0-9]/g, '') : value,
                                            }))
                                        }
                                        errors={customerErrors}
                                        disabled={savingCustomer}
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={saveCustomer}
                                        disabled={savingCustomer || !customerDetailsChanged}
                                    >
                                        حفظ بيانات العميل
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Status */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">حالة الفاتورة</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {/* التعديل لا يعتمد: الفاتورة تبقى معلّقة حتّى تُعتمد من طابور
                                المراجعة — فلا يُعرض مفتاحٌ لا يُرسَل أصلاً. */}
                            {isEmployee || isEditing ? (
                                <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-700 dark:text-amber-400">
                                    {isEditing ? (
                                        <>
                                            تبقى الفاتورة <span className="font-semibold">معلقة</span> بعد التعديل — تُعتمد من طابور المراجعة.
                                        </>
                                    ) : (
                                        <>
                                            تُحفظ الفاتورة كـ <span className="font-semibold">معلقة</span> ليراجعها المحاسب ويعتمد الدفع.
                                        </>
                                    )}
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
                                    <span className="text-muted-foreground">رصيد النقاط المتاح</span>
                                    <span className="font-medium">{selectedCustomer.availablePoints.toLocaleString('en-US')}</span>
                                </div>
                                {selectedCustomer.reservedPoints > 0 && (
                                    <p className="text-muted-foreground text-xs">
                                        محجوز {selectedCustomer.reservedPoints.toLocaleString('en-US')} نقطة على فواتير لم تُعتمد بعد — الرصيد الكلي{' '}
                                        {selectedCustomer.pointsBalance.toLocaleString('en-US')}.
                                    </p>
                                )}
                                <Input
                                    value={redeemPoints}
                                    onChange={(e) => setRedeemPoints(e.target.value.replace(/[^0-9]/g, ''))}
                                    placeholder={`نقاط للاستبدال (الحد الأدنى ${loyalty.minRedemptionPoints})`}
                                    inputMode="numeric"
                                    disabled={selectedCustomer.availablePoints < loyalty.minRedemptionPoints}
                                />
                                {selectedCustomer.availablePoints < loyalty.minRedemptionPoints ? (
                                    <p className="text-muted-foreground text-xs">الرصيد المتاح أقل من الحد الأدنى للاستبدال.</p>
                                ) : (
                                    pointsDiscount > 0 && (
                                        <>
                                            <p className="text-xs text-green-600 dark:text-green-400">
                                                خصم {formatCurrency(pointsDiscount)} مقابل {Number(redeemPoints).toLocaleString('en-US')} نقطة
                                            </p>
                                            <p className="text-muted-foreground text-xs">تُخصم النقاط من رصيد العميل عند اعتماد الفاتورة.</p>
                                        </>
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
                            {lineAgentsCommission > 0 && (
                                <div className="text-muted-foreground flex justify-between text-xs">
                                    <span>عمولات أصحاب العمولة (البنود)</span>
                                    <span>{formatCurrency(lineAgentsCommission)}</span>
                                </div>
                            )}
                            {materialsTotal > 0 && (
                                <div className="text-muted-foreground flex justify-between text-xs">
                                    <span>تكلفة الخامات (لا تُحتسب على العميل)</span>
                                    <span>{formatCurrency(materialsTotal)}</span>
                                </div>
                            )}
                            <div className="text-muted-foreground flex justify-between text-xs">
                                <span>عمولة الموظف (تقديري)</span>
                                <span>{formatCurrency(commission)}</span>
                            </div>
                        </CardContent>
                    </Card>

                    {materialsShortfall.length > 0 && (
                        <div className="flex items-start gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-700 dark:text-amber-400">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            <div className="space-y-0.5">
                                <p className="font-medium">المخزون قد لا يكفي خامات هذه الفاتورة</p>
                                {materialsShortfall.map((row) => (
                                    <p key={row.name}>
                                        {row.name}: مطلوب {formatQty(row.required)} — المتاح {formatQty(row.available)} {row.unitName ?? ''}
                                    </p>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* Invoice-level note — printed under the whole lines table,
                        unlike the per-line detail edited inside each cart row. */}
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
                            <CardTitle className="text-base">
                                طريقة الدفع{' '}
                                {isEmployee ? (
                                    <span className="text-muted-foreground text-sm font-normal">(اختياري)</span>
                                ) : (
                                    <span className="text-destructive">*</span>
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            {paymentMethods.length === 0 ? (
                                <p className={isEmployee ? 'text-muted-foreground text-sm' : 'text-destructive text-sm'}>
                                    {isEmployee
                                        ? 'لا توجد طرق دفع مفعّلة لهذا الفرع — تُحفظ الفاتورة معلّقة ويحدّدها المحاسب عند الاعتماد.'
                                        : 'لا توجد طرق دفع مفعّلة لهذا الفرع — أضف طريقة دفع من الإعدادات قبل إصدار الفواتير.'}
                                </p>
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

                                    {requiresReceipt && <ReceiptField id="receipt" onChange={setReceipt} error={errors.receipt} />}
                                </div>
                            )}
                            {errors.payment_method_id && <p className="text-destructive mt-2 text-xs">{errors.payment_method_id}</p>}
                        </CardContent>
                    </Card>

                    {/* موعد تسليم العمل — بجوار طريقة الدفع، يُعرض DD/MM/YYYY
                        ويُخزَّن YYYY-MM-DD HH:MM. لا يُقبل موعد قبل اليوم. */}
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <CalendarClock className="size-4" /> موعد تسليم العمل
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="grid grid-cols-2 gap-2">
                                <div className="space-y-1">
                                    <Label htmlFor="delivery-date" className="text-muted-foreground text-xs">
                                        التاريخ
                                    </Label>
                                    <Input
                                        id="delivery-date"
                                        type="date"
                                        min={todayIso()}
                                        value={deliveryDate}
                                        onChange={(e) => {
                                            setDeliveryDate(e.target.value);
                                            // موعد بلا وقت مبهم — يُقترح منتصف النهار ويبقى قابلاً للتعديل.
                                            if (e.target.value && !deliveryTime) setDeliveryTime('12:00');
                                            if (!e.target.value) setDeliveryTime('');
                                        }}
                                        className="h-9"
                                    />
                                </div>
                                <div className="space-y-1">
                                    <Label htmlFor="delivery-time" className="text-muted-foreground text-xs">
                                        الوقت
                                    </Label>
                                    <Input
                                        id="delivery-time"
                                        type="time"
                                        value={deliveryTime}
                                        onChange={(e) => setDeliveryTime(e.target.value)}
                                        disabled={!deliveryDate}
                                        className="h-9"
                                    />
                                </div>
                            </div>

                            {deliveryAt ? (
                                <div className="flex items-center justify-between gap-2">
                                    <p className="text-sm font-medium" dir="ltr">
                                        {formatDateTimeNumeric(deliveryAt.replace(' ', 'T'))}
                                    </p>
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setDeliveryDate('');
                                            setDeliveryTime('');
                                        }}
                                        className="text-muted-foreground hover:text-destructive text-xs"
                                    >
                                        مسح الموعد
                                    </button>
                                </div>
                            ) : (
                                <p className="text-muted-foreground text-xs">اختياري — يظهر في الفاتورة ويُذكَّر به الموظف قبل الموعد بيوم.</p>
                            )}
                            {errors.delivery_at && <p className="text-destructive text-xs">{errors.delivery_at}</p>}
                        </CardContent>
                    </Card>

                    {/* Actions */}
                    <div className="space-y-2">
                        {isEditing ? (
                            <>
                                <Button type="button" className="w-full" disabled={submitting || cart.length === 0 || hasPriceCapViolation} onClick={() => submit(false)}>
                                    <Save className="size-4" /> تحديث الفاتورة
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="w-full"
                                    disabled={submitting}
                                    onClick={() => router.get(`/invoices/service/${invoice!.id}`)}
                                >
                                    <X className="size-4" /> إلغاء
                                </Button>
                            </>
                        ) : (
                            <>
                                <Button type="button" className="w-full" disabled={submitting || cart.length === 0 || hasPriceCapViolation} onClick={() => submit(false)}>
                                    <Save className="size-4" /> حفظ الفاتورة
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="w-full"
                                    disabled={submitting || cart.length === 0 || hasPriceCapViolation}
                                    onClick={() => submit(true)}
                                >
                                    <Printer className="size-4" /> طباعة وحفظ
                                </Button>
                            </>
                        )}
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
                                        <SelectTrigger className="h-11 md:h-8">
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
                                renderLineMeta={(line) => {
                                    const parts = [`عمولة ${line.baseCommissionPct}%`];

                                    if (line.pricingType === 'sqm') parts.push('بالمتر المربع');

                                    // الموظف وحده مقيَّد بالحدَّين، فلا يُعرضان على غيره.
                                    if (isEmployee) {
                                        const unit = line.pricingType === 'sqm' ? ' للمتر' : '';

                                        if (line.maxSellingPrice !== null && line.maxSellingPrice > 0) {
                                            parts.push(`الحد الأعلى ${formatCurrency(line.maxSellingPrice)}${unit}`);
                                        }

                                        // والأرضية تُعرض قبل أن يُخطئ لا بعده — وهي
                                        // شاملة الضريبة، بلغة الحقل الذي سيكتب فيه.
                                        const floor = linePriceFloor(line, vatPct);

                                        if (floor > 0) {
                                            parts.push(`الحد الأدنى ${formatCurrency(floor)}${unit}`);
                                        }
                                    }

                                    return parts.join(' • ');
                                }}
                                // حتى سطر المتر المربع قابل لتحرير سعره: المقاس يملأ
                                // الحقل والكاشير يكتب فوقه عند الاتفاق على سعر آخر.
                                isPriceEditable={() => true}
                                getPriceError={priceBoundError}
                                // سطر المتر: الرقم في هذا العمود سعر المتر لا سعر القطعة.
                                getPriceHint={(line) => (line.pricingType === 'sqm' ? 'للمتر المربع' : null)}
                                getMaxDiscount={(line) => (line.maxDiscountPct > 0 ? line.maxDiscountPct : 100)}
                                getLineTotal={lineTotal}
                                onQtyChange={changeQty}
                                onPriceChange={(line, price) => updateLine(line.key, { unitPrice: price })}
                                onDiscountChange={setDiscount}
                                onRemove={removeLine}
                                onAddManual={addManualLine}
                                // سطر المتر بلا مقاس بلا إجمالي — يُفتح عند إضافته حتى لا
                                // يبحث الكاشير عن حقول العرض والطول.
                                isLineDetailsInitiallyOpen={(line) => line.pricingType === 'sqm' && (!line.widthCm || !line.heightCm)}
                                renderLineSummary={(line) => {
                                    if (!line.branchServiceId) return null;
                                    const isSqm = line.pricingType === 'sqm';
                                    const hasDimensions = !!line.widthCm && !!line.heightCm;
                                    const materials = lineMaterialsTotal(line);
                                    const note = line.notes.trim();

                                    if (!isSqm && !line.agentId && materials <= 0 && !note) return null;

                                    return (
                                        <>
                                            {isSqm &&
                                                (hasDimensions ? (
                                                    <LineChip>
                                                        <Ruler className="size-3" />
                                                        {line.widthCm}×{line.heightCm} سم — {round2(lineAreaSqm(line))} م²
                                                    </LineChip>
                                                ) : (
                                                    <LineChip tone="warning">
                                                        <Ruler className="size-3" /> أدخل الأبعاد
                                                    </LineChip>
                                                ))}

                                            {!!line.agentId && (
                                                <LineChip tone="info">
                                                    <BadgePercent className="size-3" /> عمولة {formatCurrency(lineAgentCommission(line, vatPct, agents))}
                                                </LineChip>
                                            )}

                                            {materials > 0 && (
                                                <LineChip>
                                                    <Package className="size-3" /> خامات {formatCurrency(materials)}
                                                </LineChip>
                                            )}

                                            {note && (
                                                <LineChip>
                                                    <StickyNote className="size-3 shrink-0" />
                                                    <span className="truncate">{note}</span>
                                                </LineChip>
                                            )}
                                        </>
                                    );
                                }}
                                renderLineDetails={(line) => {
                                    if (!line.branchServiceId) return null;
                                    const isSqm = line.pricingType === 'sqm';
                                    const hasAgent = !!line.agentId;

                                    return (
                                        <>
                                            {isSqm && (
                                                <LineSection
                                                    title="مقاس القطعة"
                                                    // السعر هنا سعر المتر المربع لا سعر القطعة، فالمقاس يضربه ولا
                                                    // يغيّره؛ وسعر متر الخدمة بجانبه للمقارنة.
                                                    aside={`سعر متر الخدمة: ${formatCurrency(line.pricePerSqm)}`}
                                                >
                                                    <div className="grid gap-3 sm:grid-cols-3">
                                                        <LineField label="العرض (سم)" htmlFor={`width-${line.key}`}>
                                                            <Input
                                                                id={`width-${line.key}`}
                                                                type="number"
                                                                min={1}
                                                                step="0.1"
                                                                value={line.widthCm ?? ''}
                                                                onChange={(e) =>
                                                                    updateLine(line.key, {
                                                                        widthCm: e.target.value === '' ? null : Math.max(0, Number(e.target.value)),
                                                                    })
                                                                }
                                                                className="h-9 text-center"
                                                                placeholder="100"
                                                            />
                                                        </LineField>
                                                        <LineField label="الطول (سم)" htmlFor={`height-${line.key}`}>
                                                            <Input
                                                                id={`height-${line.key}`}
                                                                type="number"
                                                                min={1}
                                                                step="0.1"
                                                                value={line.heightCm ?? ''}
                                                                onChange={(e) =>
                                                                    updateLine(line.key, {
                                                                        heightCm: e.target.value === '' ? null : Math.max(0, Number(e.target.value)),
                                                                    })
                                                                }
                                                                className="h-9 text-center"
                                                                placeholder="70"
                                                            />
                                                        </LineField>
                                                        <LineField label="سعر المتر المربع" htmlFor={`sqm-price-${line.key}`}>
                                                            <Input
                                                                id={`sqm-price-${line.key}`}
                                                                type="number"
                                                                min={0}
                                                                step="0.01"
                                                                value={line.unitPrice || ''}
                                                                onChange={(e) =>
                                                                    updateLine(line.key, {
                                                                        unitPrice: e.target.value === '' ? 0 : Math.max(0, Number(e.target.value)),
                                                                    })
                                                                }
                                                                className="h-9 text-center"
                                                                placeholder="0.00"
                                                            />
                                                        </LineField>
                                                    </div>
                                                    {/* الرسالة نفسها التي يعرضها عمود السعر — سقفاً كانت
                                                        أم أرضية، فلا يبقى نصٌّ يسمّي «الحد الأعلى» وحده. */}
                                                    {priceBoundError(line) && (
                                                        <p className="text-destructive text-[11px]">
                                                            سعر المتر {formatCurrency(line.unitPrice)} — {priceBoundError(line)}.
                                                        </p>
                                                    )}
                                                    {line.widthCm && line.heightCm ? (
                                                        <p className={cn(LINE_HINT_CLASS, 'text-[11px]')}>
                                                            المساحة {round2(lineAreaSqm(line))} م² × {formatCurrency(line.unitPrice)} للمتر ={' '}
                                                            {formatCurrency(linePiecePrice(line))} للقطعة
                                                            {line.qty > 1 && (
                                                                <>
                                                                    {' '}
                                                                    × {line.qty} = {formatCurrency(round2(lineUnits(line) * line.unitPrice))}
                                                                </>
                                                            )}
                                                            .
                                                            {line.pricePerSqm > 0 && round2(line.unitPrice) !== round2(line.pricePerSqm) && (
                                                                <>
                                                                    {' '}
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => updateLine(line.key, { unitPrice: line.pricePerSqm })}
                                                                        className="text-primary underline underline-offset-2"
                                                                    >
                                                                        استعادة سعر الخدمة
                                                                    </button>
                                                                </>
                                                            )}
                                                        </p>
                                                    ) : (
                                                        <p className="text-[11px] text-amber-700 dark:text-amber-400">
                                                            أدخل العرض والطول ليُحتسب إجمالي السطر — السعر أعلاه للمتر المربع لا للقطعة.
                                                        </p>
                                                    )}
                                                </LineSection>
                                            )}

                                            {/* تاسك 56: «صاحب العمولة» نزل من عمود في الجدول إلى هنا —
                                                المنتقي أولاً، وشروط العمولة تظهر بعده متى اختير مندوب. */}
                                            {agents.length > 0 && (
                                                <LineSection
                                                    title="صاحب العمولة"
                                                    aside={hasAgent ? 'تُدفع للمندوب لاحقاً — لا تُخصم من العميل' : 'اختياري'}
                                                >
                                                    <LineField label="المندوب" htmlFor={`line-agent-${line.key}`}>
                                                        <Select
                                                            value={line.agentId ? String(line.agentId) : 'none'}
                                                            onValueChange={(v) => setLineAgent(line, v === 'none' ? null : Number(v))}
                                                        >
                                                            <SelectTrigger id={`line-agent-${line.key}`} className="h-9 w-full sm:w-64">
                                                                <SelectValue placeholder="— بدون —" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="none">— بدون —</SelectItem>
                                                                {agents.map((a) => (
                                                                    <SelectItem key={a.id} value={String(a.id)}>
                                                                        {a.name}
                                                                    </SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                    </LineField>

                                                    {hasAgent && (
                                                        <div className="grid gap-3 sm:grid-cols-3">
                                                            <LineField label="نوع العمولة">
                                                                <Select
                                                                    value={line.agentCommissionType ?? 'percentage'}
                                                                    onValueChange={(v) =>
                                                                        updateLine(line.key, { agentCommissionType: v as LineAgentCommissionType })
                                                                    }
                                                                >
                                                                    <SelectTrigger className="h-9 w-full">
                                                                        <SelectValue />
                                                                    </SelectTrigger>
                                                                    <SelectContent>
                                                                        <SelectItem value="percentage">نسبة %</SelectItem>
                                                                        <SelectItem value="fixed">مبلغ ثابت</SelectItem>
                                                                        {isSqm && <SelectItem value="per_sqm">لكل م²</SelectItem>}
                                                                    </SelectContent>
                                                                </Select>
                                                            </LineField>
                                                            <LineField
                                                                label={
                                                                    line.agentCommissionType === 'percentage'
                                                                        ? 'النسبة (%)'
                                                                        : line.agentCommissionType === 'per_sqm'
                                                                          ? 'ر.س / م²'
                                                                          : 'المبلغ (ر.س)'
                                                                }
                                                                htmlFor={`agent-value-${line.key}`}
                                                            >
                                                                <Input
                                                                    id={`agent-value-${line.key}`}
                                                                    type="number"
                                                                    min={0}
                                                                    step="0.01"
                                                                    max={line.agentCommissionType === 'percentage' ? 100 : undefined}
                                                                    value={line.agentCommissionValue}
                                                                    onChange={(e) =>
                                                                        updateLine(line.key, {
                                                                            agentCommissionValue: Math.max(0, Number(e.target.value) || 0),
                                                                        })
                                                                    }
                                                                    className="h-9 text-center"
                                                                />
                                                            </LineField>
                                                            <LineField label="العمولة المحتسبة">
                                                                <LineReadout tone="info">{formatCurrency(lineAgentCommission(line, vatPct, agents))}</LineReadout>
                                                            </LineField>
                                                        </div>
                                                    )}
                                                </LineSection>
                                            )}

                                            {/* تكلفة الخامات — داخلية بالكامل: لا تظهر للعميل ولا تغيّر
                                                الإجمالي، وإنما تُخصم من أساس عمولة الموظف وحده.
                                                ولذلك لا يكتبها الموظف على نفسه (تاسك 54): تصله من
                                                تعريف الخدمة للقراءة، والخادم يتجاهل ما يرسله. */}
                                            <LineSection
                                                title={
                                                    canEditMaterials ? (
                                                        <label className="flex cursor-pointer items-center gap-2" htmlFor={`materials-${line.key}`}>
                                                            <Checkbox
                                                                id={`materials-${line.key}`}
                                                                checked={line.hasMaterials}
                                                                onCheckedChange={(checked) => updateLine(line.key, { hasMaterials: checked === true })}
                                                            />
                                                            <span>تكلفة الخامات</span>
                                                        </label>
                                                    ) : (
                                                        <span>تكلفة الخامات</span>
                                                    )
                                                }
                                                aside={
                                                    canEditMaterials
                                                        ? 'داخلية — تُخصم من عمولة الموظف ولا تظهر للعميل'
                                                        : 'تُحدَّد من إدارة الخدمة — للاطّلاع فقط'
                                                }
                                            >
                                                {line.hasMaterials ? (
                                                    <div className="grid gap-3 sm:grid-cols-3">
                                                        <LineField
                                                            label={isSqm ? 'التكلفة للمتر المربع (ر.س)' : 'التكلفة للوحدة (ر.س)'}
                                                            htmlFor={`materials-cost-${line.key}`}
                                                        >
                                                            {canEditMaterials ? (
                                                                <Input
                                                                    id={`materials-cost-${line.key}`}
                                                                    type="number"
                                                                    min={0}
                                                                    step="0.01"
                                                                    value={line.materialsCost}
                                                                    onChange={(e) =>
                                                                        updateLine(line.key, {
                                                                            materialsCost: Math.max(0, Number(e.target.value) || 0),
                                                                        })
                                                                    }
                                                                    className="h-9 text-center"
                                                                />
                                                            ) : (
                                                                <LineReadout>{formatCurrency(line.materialsCost)}</LineReadout>
                                                            )}
                                                        </LineField>
                                                        {/* العنوان يسمّي المضروب فيه فعلاً: الأمتار المربعة
                                                            لخدمة بالمتر، وعدد القطع لخدمة بالوحدة (تاسك 63). */}
                                                        <LineField
                                                            label={
                                                                isSqm
                                                                    ? `الإجمالي (× ${round2(lineUnits(line))} م²)`
                                                                    : `الإجمالي (× ${line.qty})`
                                                            }
                                                        >
                                                            <LineReadout>{formatCurrency(lineMaterialsTotal(line))}</LineReadout>
                                                        </LineField>
                                                    </div>
                                                ) : (
                                                    !canEditMaterials && (
                                                        <LineHint>لا خامات معرَّفة على هذه الخدمة.</LineHint>
                                                    )
                                                )}
                                            </LineSection>

                                            {/* Free-text detail — printed under the service name on the invoice */}
                                            <LineSection title="تفاصيل إضافية للخدمة (اختياري)" aside={`${line.notes.length}/500`}>
                                                <textarea
                                                    id={`notes-${line.key}`}
                                                    rows={2}
                                                    maxLength={500}
                                                    value={line.notes}
                                                    onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) =>
                                                        updateLine(line.key, { notes: e.target.value })
                                                    }
                                                    placeholder={
                                                        noteExamplesPlaceholder(line.noteExamples) ?? 'مثال: ورق مقوّى 300 جرام — تسليم الخميس'
                                                    }
                                                    className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[56px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                                />
                                                <LineHint>تُطبع أسفل اسم الخدمة في الفاتورة.</LineHint>
                                            </LineSection>
                                        </>
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
                saveLabel={isEditing ? 'تحديث الفاتورة' : 'حفظ الفاتورة'}
                disabled={submitting || cart.length === 0 || hasPriceCapViolation}
                onSave={() => submit(false)}
            />
        </AppLayout>
    );
}
