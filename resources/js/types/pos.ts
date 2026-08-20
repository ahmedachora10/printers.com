export interface PosProduct {
    id: number;
    name: string;
    sku: string;
    /** سعر القطعة، أو سعر المتر المربع للمنتج المسعّر بالمساحة */
    sellingPrice: number;
    currentStock: number;
    unitName: string | null;
    /** منتج يُباع ويُخصم من المخزون بالمتر المربع (تاسك 51) */
    isSqm: boolean;
}

export type ServicePricingType = 'unit' | 'sqm';

/** How a line's commission-owner (agent) share is computed. */
export type LineAgentCommissionType = 'percentage' | 'fixed' | 'per_sqm';

export interface PosService {
    id: number;
    name: string;
    baseCommissionPct: number;
    maxDiscountPct: number;
    pricingType: ServicePricingType;
    pricePerSqm: number;
    agentCommissionPerSqm: number;
    /** ready-made detail phrases set by the branch admin for this service */
    noteExamples: string[];
    isTahazir: boolean;
    /** هل للخدمة خامات، وتكلفتها الافتراضية للوحدة الواحدة */
    hasMaterials: boolean;
    materialsCost: number;
}

export interface ServiceCartLine {
    key: string;
    /** null for a manual line whose service has not been picked yet */
    branchServiceId: number | null;
    name: string;
    /** free-text detail printed under the service name on the invoice */
    notes: string;
    /** placeholder hints for `notes`, carried over from the picked service */
    noteExamples: string[];
    unitPrice: number;
    qty: number;
    discountPct: number;
    maxDiscountPct: number;
    baseCommissionPct: number;
    isTahazir: boolean;
    /** تكلفة الخامات لهذا السطر — داخلية، تُخصم من أساس عمولة الموظف فقط */
    hasMaterials: boolean;
    /** المبلغ للوحدة الواحدة؛ يُضرب في الكمية */
    materialsCost: number;
    isManual: boolean;
    pricingType: ServicePricingType;
    pricePerSqm: number;
    agentCommissionPerSqm: number;
    widthCm: number | null;
    heightCm: number | null;
    /** per-line commission owner (صاحب العمولة) — optional */
    agentId: number | null;
    agentCommissionType: LineAgentCommissionType | null;
    agentCommissionValue: number;
}

export interface PosCustomer {
    id: number;
    fullName: string;
    phone: string;
    taxNumber: string | null;
    agentId: number | null;
    pointsBalance: number;
    tier: string;
    tierLabel: string;
    tierDiscountPct: number;
    loyaltyEligible: boolean;
}

export interface PosLoyalty {
    active: boolean;
    redemptionRate: number;
    minRedemptionPoints: number;
}

export type AgentDiscountMode = 'discount' | 'rebate';
export type AgentDiscountType = 'percentage' | 'fixed';

export interface PosAgent {
    id: number;
    name: string;
    discountMode: AgentDiscountMode | null;
    discountType: AgentDiscountType;
    rate: number;
}

export interface PosPaymentMethod {
    id: number;
    name: string;
    requiresAttachment: boolean;
}

/** A DUE service invoice seeded into the POS form for its owner to re-edit. */
export interface EditServiceInvoiceLine {
    branchServiceId: number;
    name: string;
    notes: string | null;
    noteExamples: string[];
    qty: number;
    unitPrice: number;
    discountPct: number;
    maxDiscountPct: number;
    baseCommissionPct: number;
    isTahazir: boolean;
    /** لقطة الخامات المحفوظة على السطر — لا القيمة الافتراضية للخدمة */
    hasMaterials: boolean;
    materialsCost: number;
    pricingType: ServicePricingType;
    pricePerSqm: number;
    agentCommissionPerSqm: number;
    widthCm: number | null;
    heightCm: number | null;
    agentId: number | null;
    agentCommissionType: LineAgentCommissionType | null;
    agentCommissionValue: number | null;
}

export interface EditServiceInvoice {
    id: number;
    invoiceNumber: string;
    customer: PosCustomer | null;
    agentIds: number[];
    coupon: { code: string; type: 'percentage' | 'fixed'; value: number } | null;
    pointsRedeemed: number;
    paymentMethodId: number | null;
    hasReceipt: boolean;
    /** ملاحظات على مستوى الفاتورة كاملة — تختلف عن ملاحظات السطر */
    notes: string | null;
    /** موعد تسليم العمل بصيغة «YYYY-MM-DD HH:MM» كما يقرأه المنتقي */
    deliveryAt: string | null;
    lines: EditServiceInvoiceLine[];
}

export interface CartLine {
    key: string;
    productId: number | null;
    name: string;
    sku: string;
    /** سعر الوحدة — سعر المتر المربع لسطر المنتج المسعّر بالمساحة */
    unitPrice: number;
    /** الكمية المحاسَب عليها: عدد القطع، أو المساحة بالمتر المربع لسطر بالمتر */
    qty: number;
    discountPct: number;
    /** null for manual lines (no stock cap) */
    maxStock: number | null;
    unitName: string | null;
    isManual: boolean;
    /** سطر منتج مسعّر بالمتر المربع — كميته مشتقّة من المقاس × عدد القطع */
    isSqm: boolean;
    widthCm: number | null;
    heightCm: number | null;
    /** عدد القطع بهذا المقاس — لسطر المتر المربع وحده */
    pieces: number;
}

export interface PosInvoiceLine {
    name: string;
    notes?: string | null;
    sku: string | null;
    qty: number;
    unitPrice: number;
    widthCm?: number | null;
    heightCm?: number | null;
    /** عدد القطع لسطر المنتج المسعّر بالمتر — الكمية أعلاه مساحتها الإجمالية */
    pieces?: number | null;
    discountPct: number;
    subtotal: number;
}

export interface PosInvoice {
    invoiceNumber: string;
    createdAt: string | null;
    status: string;
    statusLabel: string;
    subtotal: number;
    tierDiscountAmount: number;
    couponDiscount: number;
    agentDiscount: number;
    pointsDiscount: number;
    vatPct: number;
    vatAmount: number;
    totalAmount: number;
    /** العربون وما بقي على العميل — يُطبعان تحت الإجمالي متى قُبضت دفعة */
    hasPayments: boolean;
    paidAmount: number;
    paymentRemaining: number;
    customerName: string | null;
    customerPhone: string | null;
    /** يميّز الفاتورة الضريبية (B2B) عن المبسطة (B2C) في ورقة الطباعة */
    customerTaxNumber: string | null;
    paymentMethod: string | null;
    /** ملاحظات على مستوى الفاتورة كاملة — تُطبع أسفل جدول البنود */
    notes: string | null;
    /** موعد تسليم العمل للعميل — يُطبع مع بيانات الفاتورة */
    deliveryAt?: string | null;
    lines: PosInvoiceLine[];
}

export interface PosBranch {
    name: string | null;
    phone: string | null;
    address: string | null;
    taxNumber: string | null;
    /** شعار الفرع كما يُطبع أعلى الإيصال — null إن لم يرفع الفرع شعاراً */
    logoUrl: string | null;
}
