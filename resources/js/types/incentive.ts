import type { EnumOption } from '.';

export type { EnumOption };

export interface EmployeeOption {
    id: number;
    name: string;
    branchName?: string | null;
}

export interface IncentiveTier {
    threshold: number;
    value: number;
    /** مكافأة هذه الشريحة بالريال. */
    bonus: number;
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
    /** تاسك 105: الشرائح مرتّبة بالعتبة — أدناها هي targetAmount/bonusValue. */
    tiers: IncentiveTier[];
    /** رقم أعلى شريحة مبلوغة (من 1)، أو null. */
    reachedTier: number | null;
    nextTier: { number: number; threshold: number; remaining: number } | null;
    /** ذات الشرائح لا تُصرف قبل نهاية الشهر. */
    canPayNow: boolean;
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

/** تاسك 74: حسم مطبَّق على موظف — بند مستقل بجانب الحوافز لا يمسّ عمولةً ولا مكافأة. */
export interface DeductionReasonOption extends EnumOption {
    requiresNote: boolean;
}

export interface EmployeeDeduction {
    id: number;
    userId: number;
    userName: string | null;
    branchId: number;
    branchName?: string | null;
    amount: number;
    reason: string;
    reasonLabel: string;
    reasonNote: string | null;
    /** السبب كما يُقرأ: التسمية، ونصّ «أخرى» ملحقاً بها. */
    reasonText: string;
    deductedBy: string | null;
    deductedAt: string | null;
    notes: string | null;
}

export interface PaginatedEmployeeDeduction {
    data: EmployeeDeduction[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
