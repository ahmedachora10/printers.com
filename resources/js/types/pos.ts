export interface PosProduct {
    id: number;
    name: string;
    sku: string;
    sellingPrice: number;
    currentStock: number;
    unitName: string | null;
}

export interface PosCustomer {
    id: number;
    fullName: string;
    phone: string;
}

export interface PosPaymentMethod {
    id: number;
    name: string;
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
    couponDiscount: number;
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
