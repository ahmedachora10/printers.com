export type PurchaseOrderStatus = 'draft' | 'sent' | 'partial' | 'received' | 'cancelled';

export interface PurchaseOrderLine {
    id: number;
    productId: number;
    productName: string | null;
    sku: string | null;
    orderedQty: number;
    receivedQty: number;
    remainingQty: number;
    unitCost: number;
    subtotal: number;
}

export interface PurchaseOrder {
    id: number;
    poNumber: string;
    supplierId: number | null;
    supplierName: string | null;
    status: PurchaseOrderStatus;
    statusLabel: string;
    canReceive: boolean;
    canCancel: boolean;
    canEdit: boolean;
    orderDate: string | null;
    expectedDelivery: string | null;
    orderedByName: string | null;
    total?: number;
    linesCount?: number;
    notes: string | null;
    lines?: PurchaseOrderLine[];
}

export interface PaginatedPurchaseOrder {
    data: PurchaseOrder[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}

export const PO_STATUS_BADGE: Record<PurchaseOrderStatus, string> = {
    draft: 'border-border bg-muted/60 text-muted-foreground',
    sent: 'border-blue-200 bg-blue-50 text-blue-700',
    partial: 'border-amber-200 bg-amber-50 text-amber-800',
    received: 'border-green-200 bg-green-50 text-green-700',
    cancelled: 'border-red-200 bg-red-50 text-red-700',
};
