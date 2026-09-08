export type PurchaseRequestStatus = 'pending' | 'approved' | 'rejected' | 'converted';

export interface PurchaseRequestLine {
    id: number;
    /** الفعلي: المنتج المعتمد إن وُجد، وإلا ما اقترحه مقدّم الطلب */
    productId: number | null;
    itemName: string;
    sku?: string | null;
    qty: number;
    /** تاسك 67: pinned when the request was raised — pieces or square metres. */
    isSqm: boolean;
    estimatedUnitCost: number | null;
    estimatedSubtotal: number;
    notes: string | null;

    /** تاسك 89 — ما طلبه الموظف، لا يُمسّ عند الاعتماد */
    requestedItemName: string;
    requestedQty: number;
    requestedIsSqm: boolean;
    requestedUnitCost: number | null;
    requestedSku?: string | null;

    /** تاسك 89 — ما استقرّ عليه المعتمِد (null قبل القرار) */
    approvedProductId: number | null;
    approvedProductName?: string | null;
    approvedSku?: string | null;
    approvedQty: number | null;
    approvedIsSqm: boolean | null;
    approvedUnitCost: number | null;
    wasSettled: boolean;
}

/** حركة مخزون وُلدت من اعتماد الطلب (تاسك 68). */
export interface PurchaseRequestMovement {
    id: number;
    productName: string | null;
    qty: number;
    unitCost: number | null;
    createdAt: string | null;
}

export interface PurchaseRequest {
    id: number;
    branchId: number;
    branchName?: string | null;
    requestedByName?: string | null;
    status: PurchaseRequestStatus;
    statusLabel: string;
    notes: string | null;
    decidedByName?: string | null;
    decidedAt: string | null;
    /** Set when approval fed the stock — such a request never converts (تاسك 68). */
    stockFedAt: string | null;
    decisionReason: string | null;
    purchaseOrderId: number | null;
    purchaseOrderNumber?: string | null;
    purchaseOrderSupplierName?: string | null;
    createdAt: string | null;
    estimatedTotal?: number;
    linesCount?: number;
    lines?: PurchaseRequestLine[];
    stockMovements?: PurchaseRequestMovement[];
    canDecide: boolean;
    canConvert: boolean;
}

export interface PaginatedPurchaseRequest {
    data: PurchaseRequest[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}

export interface PrProductOption {
    id: number;
    branchId: number;
    name: string;
    sku: string | null;
    costPrice: number;
    isSqm: boolean;
}

export interface PrSupplierOption {
    id: number;
    branchId: number;
    name: string;
}

export interface PrBranchOption {
    id: number;
    name: string;
}

export const PR_STATUS_BADGE: Record<PurchaseRequestStatus, string> = {
    pending: 'border-amber-200 bg-amber-50 text-amber-800',
    approved: 'border-green-200 bg-green-50 text-green-700',
    rejected: 'border-red-200 bg-red-50 text-red-700',
    converted: 'border-blue-200 bg-blue-50 text-blue-700',
};
