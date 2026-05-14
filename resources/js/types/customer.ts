export type CustomerType = 'individual' | 'corporate';
export type CustomerTier = 'none' | 'bronze' | 'silver' | 'gold';

export interface Customer {
    id: number;
    fullName: string;
    phone: string;
    email: string | null;
    branchId: number;
    customerType: {
        value: CustomerType;
        label: string;
    };
    companyName: string | null;
    creditLimit: number | null;
    agentId: number | null;
    agent?: { id: number; name: string } | null;
    pointsBalance: number;
    cumulativeSpend: number;
    tier: {
        value: CustomerTier;
        label: string;
    };
    notes: string | null;
    isActive: boolean;
    createdAt: string;
    updatedAt: string;
}

export interface PaginatedCustomer {
    data: Customer[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}

export interface CustomerPageStats {
    [customerId: number]: {
        invoiceCount: number;
        outstanding: number;
        lastVisit: string | null;
    };
}

export interface CustomerFinancialSummary {
    invoiceCount: number;
    totalBilled: number;
    totalPaid: number;
    totalOutstanding: number;
    creditLimit: number | null;
    availableCredit: number | null;
}

export interface LoyaltyTransaction {
    id: number;
    type: 'earn' | 'redeem' | 'manual_adjust' | 'expire';
    points: number;
    balance_after: number;
    notes: string | null;
    created_at: string;
}

export interface InvoiceHistoryItem {
    id: number;
    invoice_number: string;
    total_amount: number;
    status: 'paid' | 'due' | 'cancelled';
    created_at: string;
    type: 'service' | 'product';
}

export interface OutstandingReportItem {
    id: number;
    fullName: string;
    phone: string;
    companyName: string | null;
    totalDue: number;
    oldestDue: string;
}
