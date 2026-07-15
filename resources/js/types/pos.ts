export interface PosProduct {
    id: number;
    name: string;
    sku: string;
    sellingPrice: number;
    currentStock: number;
    unitName: string | null;
}

export interface PosService {
    id: number;
    name: string;
    baseCommissionPct: number;
    maxDiscountPct: number;
    isTahazir: boolean;
}

export interface ServiceCartLine {
    key: string;
    /** null for a manual line whose service has not been picked yet */
    branchServiceId: number | null;
    name: string;
    unitPrice: number;
    qty: number;
    discountPct: number;
    maxDiscountPct: number;
    baseCommissionPct: number;
    isTahazir: boolean;
    isManual: boolean;
}

export interface PosCustomer {
    id: number;
    fullName: string;
    phone: string;
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
    qty: number;
    unitPrice: number;
    discountPct: number;
    maxDiscountPct: number;
    baseCommissionPct: number;
    isTahazir: boolean;
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
    lines: EditServiceInvoiceLine[];
}

export interface CartLine {
    key: string;
    productId: number | null;
    name: string;
    sku: string;
    unitPrice: number;
    qty: number;
    discountPct: number;
    /** null for manual lines (no stock cap) */
    maxStock: number | null;
    unitName: string | null;
    isManual: boolean;
}

export interface PosInvoiceLine {
    name: string;
    sku: string | null;
    qty: number;
    unitPrice: number;
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
    customerName: string | null;
    customerPhone: string | null;
    paymentMethod: string | null;
    lines: PosInvoiceLine[];
}

export interface PosBranch {
    name: string | null;
    phone: string | null;
    address: string | null;
    taxNumber: string | null;
}
