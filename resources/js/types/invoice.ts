export type InvoiceType = 'product' | 'service';
export type InvoiceStatus = 'paid' | 'due' | 'cancelled';

export interface InvoiceLine {
    name: string;
    sku: string | null;
    qty: number;
    unitPrice: number;
    discountPct: number;
    subtotal: number;
    commissionAmount: number | null;
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

export interface Invoice {
    id: number;
    type: InvoiceType;
    typeLabel: string;
    invoiceNumber: string;
    createdAt: string | null;
    paidAt: string | null;
    status: InvoiceStatus;
    statusLabel: string;
    subtotal: number;
    tierDiscountPct: number;
    tierDiscountAmount: number;
    couponDiscount: number;
    agentDiscount: number;
    agentRebate: number;
    agentName: string | null;
    pointsRedeemed: number;
    pointsDiscount: number;
    vatPct: number;
    vatAmount: number;
    totalAmount: number;
    employeeCommission: number | null;
    customerName: string | null;
    customerPhone: string | null;
    paymentMethod: string | null;
    receiptUrl: string | null;
    lines: InvoiceLine[];
    refundedTotal: number;
    refundableRemaining: number;
    isFullyRefunded: boolean;
    canRefund: boolean;
    canApprovePayment: boolean;
    canEdit: boolean;
    canDelete: boolean;
    refunds?: InvoiceRefund[];
    branch: InvoiceBranch;
}

export interface InvoiceListItem {
    id: number;
    type: InvoiceType;
    typeLabel: string;
    invoiceNumber: string;
    totalAmount: number;
    status: InvoiceStatus;
    statusLabel: string;
    customerName: string | null;
    employeeName: string | null;
    createdAt: string;
    canEdit: boolean;
    canDelete: boolean;
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
}
