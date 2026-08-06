export type InvoiceType = 'product' | 'service';
export type InvoiceStatus = 'paid' | 'due' | 'cancelled' | 'returned';

export interface InvoiceLine {
    name: string;
    /** Free-text detail typed at the POS; service lines only. */
    notes: string | null;
    sku: string | null;
    qty: number;
    unitPrice: number;
    widthCm: number | null;
    heightCm: number | null;
    discountPct: number;
    subtotal: number;
    commissionAmount: number | null;
    lineAgentName: string | null;
    lineAgentCommissionAmount: number | null;
}

export interface InvoiceBranch {
    name: string | null;
    phone: string | null;
    address: string | null;
    taxNumber: string | null;
    logoUrl: string | null;
}

export interface InvoiceRefund {
    id: number;
    amount: number;
    reason: string;
    stockReversed: boolean;
    userName: string | null;
    createdAt: string | null;
}

export interface InvoiceAgent {
    name: string | null;
    mode: 'discount' | 'rebate';
    rebate: number;
    lineCommission: number;
    discount: number;
    isRebatePaid: boolean;
}

export interface Invoice {
    id: number;
    type: InvoiceType;
    typeLabel: string;
    invoiceNumber: string;
    createdAt: string | null;
    paidAt: string | null;
    status: InvoiceStatus;
    statusLabel: string;
    /** Why a reviewer rejected the invoice — service invoices only. */
    cancellationReason: string | null;
    cancelledByName: string | null;
    cancelledAt: string | null;
    subtotal: number;
    tierDiscountPct: number;
    tierDiscountAmount: number;
    couponDiscount: number;
    agentDiscount: number;
    agents: InvoiceAgent[];
    pointsRedeemed: number;
    pointsDiscount: number;
    vatPct: number;
    vatAmount: number;
    totalAmount: number;
    employeeCommission: number | null;
    customerName: string | null;
    customerPhone: string | null;
    customerTaxNumber: string | null;
    paymentMethod: string | null;
    /** ملاحظات على مستوى الفاتورة كاملة — تختلف عن ملاحظات السطر */
    notes: string | null;
    receiptUrl: string | null;
    lines: InvoiceLine[];
    refundedTotal: number;
    refundableRemaining: number;
    isFullyRefunded: boolean;
    canRefund: boolean;
    canApprovePayment: boolean;
    canEdit: boolean;
    canReturn: boolean;
    refunds?: InvoiceRefund[];
    branch: InvoiceBranch;
}

export interface InvoiceListItem {
    id: number;
    type: InvoiceType;
    typeLabel: string;
    serviceNames: string[];
    invoiceNumber: string;
    totalAmount: number;
    status: InvoiceStatus;
    statusLabel: string;
    /** Why a reviewer rejected the invoice — service invoices only. */
    cancellationReason: string | null;
    customerId: number | null;
    customerName: string | null;
    customerPhone: string | null;
    customerTaxNumber: string | null;
    employeeName: string | null;
    /** Super-admins only — omitted from the payload for every other role. */
    branchName?: string | null;
    createdAt: string;
    canEdit: boolean;
    canReturn: boolean;
    /** Owner of an invoice that is already returned — the control shows, disabled. */
    returnLocked: boolean;
    canEditCustomer: boolean;
}

export interface PaginatedInvoice {
    data: InvoiceListItem[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}

export interface InvoiceFilters {
    search?: string;
    type?: string;
    status?: string;
    date_from?: string;
    date_to?: string;
    branch_id?: string;
}
