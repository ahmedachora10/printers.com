export type ReturnRequestStatus = 'pending' | 'completed' | 'rejected';

/** تاسك 135 — طلب استرجاع فاتورة خدمة يرفعه الموظف. */
export interface ReturnRequestItem {
    id: number;
    invoiceId: number;
    invoiceNumber: string | null;
    branchId: number;
    branchName: string | null;
    totalAmount: number;
    /** ما سيُردّ لو اعتُمد الآن، أو مبلغ المرتجع بعد الإتمام */
    amount: number;
    paymentMethodName: string | null;
    reason: string | null;
    status: ReturnRequestStatus;
    statusLabel: string;
    requesterName: string | null;
    deciderName: string | null;
    rejectionReason: string | null;
    createdAt: string | null;
    decidedAt: string | null;
    canDecide: boolean;
}

export interface PaginatedReturnRequest {
    data: ReturnRequestItem[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
