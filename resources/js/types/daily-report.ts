export interface DailyReportRow {
    date: string;
    /** Set only in detailed mode (two or more employees selected). */
    employeeId: number | null;
    employeeName: string | null;
    /** Closing row summing the day's employee rows. */
    isTotal: boolean;
    products: number;
    services: number;
    total: number;
    /** ما حُصِّل فعلاً في اليوم من دفعات الفواتير — يختلف عن الإجمالي المستحق. */
    collected: number;
    commission: number;
    purchases: number;
    remaining: number;
    vat: number;
}

export interface DailyReportTotals {
    dayCount: number;
    products: number;
    services: number;
    total: number;
    collected: number;
    commission: number;
    purchases: number;
    remaining: number;
    vat: number;
}

export interface DailyReportFilters {
    from: string | null;
    to: string | null;
    branch: string | null;
    /** Comma-separated employee ids, e.g. "3,7". */
    employee: string | null;
}
