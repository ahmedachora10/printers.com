export interface ManagedUser {
    id: number;
    name: string;
    username: string;
    email: string;
    phone: string | null;
    branchId: number | null;
    branchName: string | null;
    role: string | null;
    roleLabel: string | null;
    salary: number;
    baseCommissionPct: number;
    referralCommissionPct: number;
    joinedDate: string | null;
    isActive: boolean;
    createdAt: string;
}

export interface RoleOption {
    value: string;
    label: string;
}

export interface BranchOption {
    id: number;
    name: string;
}

export interface PaginatedUser {
    data: ManagedUser[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}

export interface UserCommissionSummary {
    totalEarned: number;
    totalPaid: number;
    pending: number;
    tahazirEarned: number;
    standardEarned: number;
}

export interface UserSalesSummary {
    serviceCount: number;
    serviceTotal: number;
    productCount: number;
    productTotal: number;
}

export interface UserInvoiceHistoryItem {
    id: number;
    type: 'service' | 'product';
    invoice_number: string;
    total_amount: number | string;
    status: string;
    created_at: string;
}

export interface UserCommissionLedgerItem {
    id: number;
    amount: number | string;
    is_tahazir: boolean | number;
    source_type: string;
    earned_at: string;
    paid_at: string | null;
}
