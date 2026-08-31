export interface CommissionReportSummaryRow {
    userId: number;
    userName: string;
    lineCount: number;
    earned: number;
    paid: number;
    pending: number;
    tahazir: number;
    /** Agent (مندوب) line-commissions on the approved invoices this employee raised. */
    lineCommission: number;
    /** تكلفة الخامات المخصومة من أساس العمولة على تلك الفواتير — داخلية. */
    materials: number;
}

/** One calendar day of the filtered range — quiet days are present and read zero. */
export interface CommissionReportDayRow {
    date: string;
    lineCount: number;
    earned: number;
    paid: number;
    pending: number;
    tahazir: number;
    lineCommission: number;
    materials: number;
}

export interface CommissionReportLine {
    id: number;
    userId: number;
    userName: string;
    invoiceNumber: string;
    invoiceStatus: 'paid' | 'due' | 'cancelled' | 'returned';
    serviceName: string;
    amount: number;
    /** The مندوب's share of this same line — shown beside `amount`, never added to it. */
    lineCommission: number;
    /** تكلفة خامات هذا السطر — خُصمت أصلاً من `amount`، تُعرض للتوضيح. */
    materials: number;
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
    lineCommission: number;
    materials: number;
    lineCount: number;
}

export interface CommissionReportFilters {
    from: string | null;
    to: string | null;
    employee: string | null;
    branch: string | null;
    status: 'all' | 'paid' | 'pending';
}

/** تقرير الحوافز والخصومات — صفٌّ لكل موظف: ما استُهدف منه وما صُرف له وما حُسم عليه. */
export interface IncentiveReportSummaryRow {
    userId: number;
    userName: string | null;
    branchName: string | null;
    planCount: number;
    target: number;
    achieved: number;
    progressPct: number;
    /** مكافآت الخطط التي بلغت هدفها — مستحقةً كانت أو مصروفة. */
    bonusEarned: number;
    bonusPaid: number;
    deductions: number;
    deductionCount: number;
    /** المصروف ناقص الخصومات — قراءةٌ لا قيد. */
    net: number;
}

export interface IncentiveReportPlanRow {
    id: number;
    userId: number;
    userName: string | null;
    branchName: string | null;
    periodLabel: string;
    target: number;
    achieved: number;
    progressPct: number;
    bonusAmount: number;
    bonusPaid: number;
    status: string;
    statusLabel: string;
}

export interface IncentiveReportDeductionRow {
    id: number;
    userId: number;
    userName: string | null;
    branchName: string | null;
    amount: number;
    reasonLabel: string;
    reasonText: string;
    deductedBy: string | null;
    deductedAt: string | null;
    notes: string | null;
}

export interface IncentiveReportReasonRow {
    reason: string;
    reasonLabel: string;
    count: number;
    amount: number;
}

export interface IncentiveReportTotals {
    employeeCount: number;
    planCount: number;
    target: number;
    achieved: number;
    progressPct: number;
    bonusEarned: number;
    bonusPaid: number;
    deductions: number;
    deductionCount: number;
    net: number;
}

export interface IncentiveReportFilters {
    from: string | null;
    to: string | null;
    branch: string | null;
    employee: string | null;
    status: string | null;
}
