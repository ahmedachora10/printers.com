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
    productId: number;
    name: string;
    sku: string;
    unitPrice: number;
    qty: number;
    discountPct: number;
    maxStock: number;
    unitName: string | null;
}
