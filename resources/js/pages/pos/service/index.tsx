import InvoiceCustomerFields, { type InvoiceCustomerErrors, type InvoiceCustomerFormData } from '@/components/invoices/invoice-customer-fields';
import { PosCartTable } from '@/components/pos/cart-table';
import { AsyncCombobox, type AsyncOption } from '@/components/ui/async-combobox';
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
import { Award, Paperclip, Printer, Save, Search, Tag, X } from 'lucide-react';
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

const lineTotal = (line: ServiceCartLine) => round2(line.qty * line.unitPrice * (1 - line.discountPct / 100));

/** Area of one piece in m² from the entered cm dimensions. */
const lineAreaSqm = (line: ServiceCartLine) => ((line.widthCm ?? 0) / 100) * ((line.heightCm ?? 0) / 100);

/** Per-piece price of a sqm line — mirrors the server (rounded before qty). */
const sqmUnitPrice = (line: ServiceCartLine) => round2(lineAreaSqm(line) * line.pricePerSqm);

/** The line's commission-owner share — mirrors the server formulas. */
function lineAgentCommission(line: ServiceCartLine): number {
    if (!line.agentId || !line.agentCommissionType) return 0;
    switch (line.agentCommissionType) {
        case 'percentage':
            return round2((lineTotal(line) * line.agentCommissionValue) / 100);
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
                unitPrice: l.unitPrice,
                qty: l.qty,
                discountPct: l.discountPct,
                maxDiscountPct: l.maxDiscountPct,
                baseCommissionPct: l.baseCommissionPct,
                isTahazir: l.isTahazir,
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
    // A service invoice may carry several agents (shared rebate).
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

    // The agent list stays preloaded (small); only agents not yet added appear
    // in the picker so the cashier can stack several onto one invoice.
    const agentOptions = useMemo<ComboboxOption[]>(
        () =>
            agents
                .filter((a) => !agentIds.includes(a.id))
                .map((a) => ({
                    value: String(a.id),
                    label: `${a.name} (${a.discountMode === 'rebate' ? 'عمولة' : 'خصم'} ${a.rate}${a.discountType === 'fixed' ? ' ر.س' : '%'})`,
                })),
        [agents, agentIds],
    );

    const subtotal = useMemo(() => round2(cart.reduce((sum, l) => sum + lineTotal(l), 0)), [cart]);
    // Gross line commission before invoice-level discounts; scaled down below.
    const grossCommission = useMemo(() => round2(cart.reduce((sum, l) => sum + (lineTotal(l) * l.baseCommissionPct) / 100, 0)), [cart]);
    const selectedAgents = useMemo(() => agents.filter((a) => agentIds.includes(a.id)), [agentIds, agents]);
    const selectedPaymentMethod = useMemo(() => paymentMethods.find((m) => m.id === paymentMethodId) ?? null, [paymentMethods, paymentMethodId]);
    const requiresReceipt = selectedPaymentMethod?.requiresAttachment ?? false;

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
    const taxableBase = useMemo(() => round2(afterAgent - pointsDiscount), [afterAgent, pointsDiscount]);
    // Employee commission is earned on the net service value after every
    // invoice-level discount (tier, coupon, agent discount, points), mirroring
    // the server: scale the gross commission by taxableBase / subtotal. Stays an
    // estimate — it uses the service base rate, not the employee's own rate.
    const commission = useMemo(
        () => (subtotal > 0 ? round2((grossCommission * taxableBase) / subtotal) : 0),
        [grossCommission, taxableBase, subtotal],
    );
    const vatAmount = useMemo(() => round2((taxableBase * vatPct) / 100), [taxableBase, vatPct]);
    const total = useMemo(() => round2(taxableBase + vatAmount), [taxableBase, vatAmount]);
    // Each rebate-mode agent earns independently on the final total; the preview
    // shows their combined rebate.
    const agentRebate = useMemo(
        () =>
            round2(
                selectedAgents
                    .filter((a) => a.discountMode === 'rebate')
                    .reduce((sum, a) => sum + Math.min(a.discountType === 'fixed' ? a.rate : (total * a.rate) / 100, total), 0),
            ),
        [selectedAgents, total],
    );
    // Per-line commission owners' shares — informational: paid to the agents
    // later, never deducted from the customer's total.
    const lineAgentsCommission = useMemo(() => round2(cart.reduce((sum, l) => sum + lineAgentCommission(l), 0)), [cart]);

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
                    unitPrice: 0,
                    qty: 1,
                    discountPct: 0,
                    maxDiscountPct: s.maxDiscountPct,
                    baseCommissionPct: s.baseCommissionPct,
                    isTahazir: s.isTahazir,
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
                unitPrice: 0,
                qty: 1,
                discountPct: 0,
                maxDiscountPct: 0,
                baseCommissionPct: 0,
                isTahazir: false,
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
            maxDiscountPct: s.maxDiscountPct,
            baseCommissionPct: s.baseCommissionPct,
            isTahazir: s.isTahazir,
            discountPct: Math.min(cap, line.discountPct),
            pricingType: s.pricingType,
            pricePerSqm: s.pricePerSqm,
            agentCommissionPerSqm: s.agentCommissionPerSqm,
            // A sqm service derives its price from dimensions; reset until entered.
            ...(s.pricingType === 'sqm' ? { unitPrice: 0, widthCm: null, heightCm: null } : { widthCm: null, heightCm: null }),
            // A per_sqm commission type no longer applies on a unit service.
            ...(s.pricingType !== 'sqm' && line.agentCommissionType === 'per_sqm'
                ? { agentCommissionType: 'percentage' as LineAgentCommissionType, agentCommissionValue: 0 }
                : {}),
        });
    }

    /** Update a sqm line's dimensions and re-derive its per-piece price. */
    function setDimensions(line: ServiceCartLine, patch: { widthCm?: number | null; heightCm?: number | null }) {
        const next = { ...line, ...patch };
        updateLine(line.key, {
            ...patch,
            unitPrice: sqmUnitPrice(next),
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
            lines: cart.map((l) => ({
                branch_service_id: l.branchServiceId,
                notes: l.notes.trim() || null,
                qty: l.qty,
                unit_price: l.unitPrice,
                discount_pct: l.discountPct,
                width_cm: l.pricingType === 'sqm' ? l.widthCm : null,
                height_cm: l.pricingType === 'sqm' ? l.heightCm : null,
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

            <div className="grid gap-4 p-4 lg:grid-cols-3">
                {/* Sidebar — customer, status, coupon, totals, payment, actions */}
                <div className="space-y-4 lg:col-span-1">
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

                    {/* Agents — several may be attached to one invoice */}
                    {agents.length > 0 && (
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">المناديب</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                <Combobox
                                    options={agentOptions}
                                    value=""
                                    onChange={(v) => {
                                        const id = Number(v);
                                        if (id) setAgentIds((prev) => (prev.includes(id) ? prev : [...prev, id]));
                                    }}
                                    placeholder="— إضافة مندوب —"
                                    searchPlaceholder="بحث عن مندوب"
                                    emptyText="لا يوجد مندوب مطابق"
                                    triggerClassName="w-full"
                                    className="w-[var(--radix-popover-trigger-width)] min-w-56"
                                />
                                {selectedAgents.length > 0 && (
                                    <div className="space-y-1.5">
                                        {selectedAgents.map((a) => (
                                            <div
                                                key={a.id}
                                                className="bg-muted/40 flex items-center justify-between gap-2 rounded-md border px-2 py-1.5"
                                            >
                                                <div className="min-w-0 text-xs">
                                                    <p className="truncate font-medium">{a.name}</p>
                                                    <p className="text-muted-foreground">
                                                        {a.discountMode === 'rebate'
                                                            ? `عمولة مرتجعة ${a.rate}${a.discountType === 'fixed' ? ' ر.س' : '%'} على الإجمالي`
                                                            : `خصم ${a.rate}${a.discountType === 'fixed' ? ' ر.س' : '%'} على الفاتورة`}
                                                    </p>
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() => setAgentIds((prev) => prev.filter((id) => id !== a.id))}
                                                    className="text-muted-foreground hover:text-destructive shrink-0"
                                                >
                                                    <X className="size-4" />
                                                </button>
                                            </div>
                                        ))}
                                    </div>
                                )}
                                {errors.agent_ids && <p className="text-destructive text-xs">{errors.agent_ids}</p>}
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
                                    <span>خصم المندوب</span>
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
                        {isEditing ? (
                            <>
                                <Button type="button" className="w-full" disabled={submitting || cart.length === 0} onClick={() => submit(false)}>
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
                            </>
                        )}
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
                                renderLineMeta={(line) =>
                                    line.pricingType === 'sqm'
                                        ? `عمولة ${line.baseCommissionPct}% • بالمتر المربع`
                                        : `عمولة ${line.baseCommissionPct}%`
                                }
                                isPriceEditable={(line) => line.pricingType !== 'sqm'}
                                getMaxDiscount={(line) => (line.maxDiscountPct > 0 ? line.maxDiscountPct : 100)}
                                getLineTotal={lineTotal}
                                onQtyChange={changeQty}
                                onPriceChange={(line, price) => updateLine(line.key, { unitPrice: price })}
                                onDiscountChange={setDiscount}
                                onRemove={removeLine}
                                onAddManual={addManualLine}
                                lineAgentLabel="صاحب العمولة"
                                renderLineAgent={
                                    agents.length > 0
                                        ? (line) =>
                                              line.branchServiceId ? (
                                                  <Select
                                                      value={line.agentId ? String(line.agentId) : 'none'}
                                                      onValueChange={(v) => setLineAgent(line, v === 'none' ? null : Number(v))}
                                                  >
                                                      <SelectTrigger className="h-8 w-full">
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
                                              ) : null
                                        : undefined
                                }
                                renderLineDetails={(line) => {
                                    if (!line.branchServiceId) return null;
                                    const isSqm = line.pricingType === 'sqm';
                                    const hasAgent = !!line.agentId;
                                    const commissionPreview = lineAgentCommission(line);

                                    return (
                                        <div className="space-y-2">
                                        <div className="flex flex-wrap items-end gap-3">
                                            {isSqm && (
                                                <>
                                                    <div className="space-y-1">
                                                        <Label className="text-muted-foreground text-xs">العرض (سم)</Label>
                                                        <Input
                                                            type="number"
                                                            min={1}
                                                            step="0.1"
                                                            value={line.widthCm ?? ''}
                                                            onChange={(e) =>
                                                                setDimensions(line, {
                                                                    widthCm: e.target.value === '' ? null : Math.max(0, Number(e.target.value)),
                                                                })
                                                            }
                                                            className="h-8 w-24 text-center"
                                                            placeholder="100"
                                                        />
                                                    </div>
                                                    <div className="space-y-1">
                                                        <Label className="text-muted-foreground text-xs">الطول (سم)</Label>
                                                        <Input
                                                            type="number"
                                                            min={1}
                                                            step="0.1"
                                                            value={line.heightCm ?? ''}
                                                            onChange={(e) =>
                                                                setDimensions(line, {
                                                                    heightCm: e.target.value === '' ? null : Math.max(0, Number(e.target.value)),
                                                                })
                                                            }
                                                            className="h-8 w-24 text-center"
                                                            placeholder="70"
                                                        />
                                                    </div>
                                                    <p className="text-muted-foreground pb-1.5 text-xs">
                                                        {line.widthCm && line.heightCm
                                                            ? `المساحة: ${round2(lineAreaSqm(line))} م² × ${formatCurrency(line.pricePerSqm)} = ${formatCurrency(sqmUnitPrice(line))} للقطعة`
                                                            : `سعر المتر: ${formatCurrency(line.pricePerSqm)}`}
                                                    </p>
                                                </>
                                            )}

                                            {hasAgent && (
                                                <>
                                                    <div className="space-y-1">
                                                        <Label className="text-muted-foreground text-xs">نوع العمولة</Label>
                                                        <Select
                                                            value={line.agentCommissionType ?? 'percentage'}
                                                            onValueChange={(v) =>
                                                                updateLine(line.key, { agentCommissionType: v as LineAgentCommissionType })
                                                            }
                                                        >
                                                            <SelectTrigger className="h-8 w-32">
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="percentage">نسبة %</SelectItem>
                                                                <SelectItem value="fixed">مبلغ ثابت</SelectItem>
                                                                {isSqm && <SelectItem value="per_sqm">لكل م²</SelectItem>}
                                                            </SelectContent>
                                                        </Select>
                                                    </div>
                                                    <div className="space-y-1">
                                                        <Label className="text-muted-foreground text-xs">
                                                            {line.agentCommissionType === 'percentage'
                                                                ? 'النسبة (%)'
                                                                : line.agentCommissionType === 'per_sqm'
                                                                  ? 'ر.س / م²'
                                                                  : 'المبلغ (ر.س)'}
                                                        </Label>
                                                        <Input
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
                                                            className="h-8 w-24 text-center"
                                                        />
                                                    </div>
                                                    <p className="pb-1.5 text-xs font-medium text-sky-700 dark:text-sky-400">
                                                        العمولة: {formatCurrency(commissionPreview)}
                                                    </p>
                                                </>
                                            )}
                                        </div>

                                        {/* Free-text detail — printed under the service name on the invoice */}
                                        <div className="space-y-1">
                                            <Label className="text-muted-foreground text-xs" htmlFor={`notes-${line.key}`}>
                                                تفاصيل إضافية للخدمة (اختياري)
                                            </Label>
                                            <textarea
                                                id={`notes-${line.key}`}
                                                rows={2}
                                                maxLength={500}
                                                value={line.notes}
                                                onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) =>
                                                    updateLine(line.key, { notes: e.target.value })
                                                }
                                                placeholder="مثال: ورق مقوّى 300 جرام — تسليم الخميس"
                                                className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring flex min-h-[56px] w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                            />
                                        </div>
                                        </div>
                                    );
                                }}
                            />
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
