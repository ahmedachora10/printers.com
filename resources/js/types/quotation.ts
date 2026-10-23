export type QuotationStatus = 'new' | 'pending' | 'approved' | 'rejected' | 'converted';

export interface QuotationListItem {
    id: number;
    number: string;
    type: 'service' | 'product';
    status: QuotationStatus;
    statusLabel: string;
    createdAt: string | null;
    customerName: string | null;
    customerPhone: string | null;
    employeeName: string | null;
    branchName: string | null;
    totalAmount: number;
    rejectionReason: string | null;
    /** الفاتورة الناتجة عن التحويل */
    invoiceId: number | null;
    invoiceNumber: string | null;
    can: {
        update: boolean;
        changeStatus: boolean;
        print: boolean;
        convert: boolean;
    };
}

export interface PaginatedQuotations {
    data: QuotationListItem[];
    meta: { current_page: number; last_page: number; total: number; per_page: number; from: number | null; to: number | null };
}
