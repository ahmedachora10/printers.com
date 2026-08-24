export type RefundSourceType = 'product' | 'service';

export interface RefundListItem {
    id: number;
    sourceType: RefundSourceType;
    sourceTypeLabel: string;
    invoiceId: number | null;
    invoiceNumber: string | null;
    amount: number;
    reason: string;
    stockReversed: boolean;
    userName: string | null;
    createdAt: string | null;
}

export interface PaginatedRefund {
    data: RefundListItem[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}

export interface RefundFilters {
    source_type?: string;
    date_from?: string;
    date_to?: string;
}

export interface InvoiceLookupResult {
    id: number;
    number: string;
    type: RefundSourceType;
    typeLabel: string;
    status: string;
    totalAmount: number;
    alreadyRefunded: number;
    refundable: number;
    customerName: string | null;
    hasProducts: boolean;
    /** فاتورة خدمة خُصمت خاماتها من المخزون فعلاً — أي اعتُمدت */
    hasMaterials: boolean;
    stockReversed: boolean;
}
