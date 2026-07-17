export interface DailyReportRow {
    date: string;
    products: number;
    services: number;
    total: number;
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
    commission: number;
    purchases: number;
    remaining: number;
    vat: number;
}

export interface DailyReportFilters {
    from: string | null;
    to: string | null;
    branch: string | null;
    employee: string | null;
}
