export interface SalesReportTotals {
    invoiceCount: number;
    subtotal: number;
    discounts: number;
    vat: number;
    total: number;
}

export interface SalesReportTypeRow {
    type: 'product' | 'service';
    label: string;
    count: number;
    subtotal: number;
    discounts: number;
    vat: number;
    total: number;
}

export interface SalesReportDayRow {
    date: string;
    count: number;
    total: number;
}

export interface SalesReportEmployeeRow {
    userId: number;
    userName: string;
    count: number;
    total: number;
}

export interface SalesReportPaymentMethodRow {
    methodId: number | null;
    methodName: string;
    count: number;
    total: number;
}

export interface SalesReportBranchRow {
    branchId: number;
    branchName: string;
    count: number;
    total: number;
}

export interface SalesReportFilters {
    from: string | null;
    to: string | null;
    branch: string | null;
    type: 'all' | 'product' | 'service';
}
