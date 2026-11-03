export interface CommissionEmployeeRow {
    userId: number;
    userName: string;
    salary: number;
    totalEarned: number;
    totalPaid: number;
    pending: number;
    incentives: number;
    deductions: number;
    netTotal: number;
}

export interface CommissionSummary {
    totalEarned: number;
    totalPaid: number;
    pending: number;
    totalSalaries: number;
    totalIncentives: number;
    totalDeductions: number;
    totalNet: number;
}

export interface CommissionPayment {
    id: number;
    userId: number;
    userName?: string;
    branchId: number;
    periodStart: string | null;
    periodEnd: string | null;
    totalAmount: number | string;
    paidByName?: string;
    paidAt: string | null;
    notes: string | null;
}

export interface PaginatedCommissionPayment {
    data: CommissionPayment[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
