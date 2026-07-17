export interface CommissionReportSummaryRow {
    userId: number;
    userName: string;
    lineCount: number;
    earned: number;
    paid: number;
    pending: number;
    tahazir: number;
}

export interface CommissionReportLine {
    id: number;
    userId: number;
    userName: string;
    invoiceNumber: string;
    invoiceStatus: 'paid' | 'due' | 'cancelled';
    serviceName: string;
    amount: number;
    isTahazir: boolean;
    tierApplied: number | null;
    sourceType: string;
    sourceLabel: string;
    earnedAt: string | null;
    paidAt: string | null;
}

export interface CommissionReportTotals {
    earned: number;
    paid: number;
    pending: number;
    tahazir: number;
    lineCount: number;
}

export interface CommissionReportFilters {
    from: string | null;
    to: string | null;
    employee: string | null;
    branch: string | null;
    status: 'all' | 'paid' | 'pending';
}
