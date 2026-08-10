/** تقرير المصروفات (تاسك 32) — يطابق ExpenseReportController. */

export interface ExpenseReportTotals {
    expenseCount: number;
    total: number;
    average: number;
    topCategoryName: string | null;
    topCategoryTotal: number;
}

export interface ExpenseReportCategoryRow {
    categoryId: number | null;
    name: string;
    count: number;
    total: number;
    /** نصيب الفئة من إجمالي المصروفات، بالمئة. */
    pct: number;
}

export interface ExpenseReportDayRow {
    date: string;
    count: number;
    total: number;
}

export interface ExpenseReportRow {
    id: number;
    date: string;
    categoryName: string;
    branchName: string | null;
    supplierName: string | null;
    qty: number;
    unitPrice: number;
    total: number;
    receiptReference: string | null;
    userName: string | null;
}

export interface ExpenseReportFilters {
    from?: string | null;
    to?: string | null;
    branch?: string | null;
    category?: string | null;
}
