export interface EnumOption {
    value: string;
    label: string;
}

export interface EmployeeOption {
    id: number;
    name: string;
    branchName?: string | null;
}

export interface IncentivePlan {
    id: number;
    userId: number;
    userName: string | null;
    branchId: number;
    branchName?: string | null;
    periodMonth: number;
    periodYear: number;
    periodLabel: string;
    targetAmount: number;
    bonusType: string;
    bonusTypeLabel: string;
    bonusValue: number;
    achievedAmount: number;
    progressPct: number;
    bonusAmount: number;
    status: string;
    statusLabel: string;
    isTargetMet: boolean;
    notes: string | null;
    paidAmount: number | null;
    paidAt: string | null;
    paidBy: string | null;
}

export interface PaginatedIncentivePlan {
    data: IncentivePlan[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
