export interface SalesReportTotals {
    invoiceCount: number;
    subtotal: number;
    discounts: number;
    vat: number;
    /** جملة ما رُدّ للعملاء، موجبةً — و`total` صافٍ منها */
    refunds: number;
    total: number;
    /** مصروفات المدى من جدول expenses وحده — لا تشمل قيمة المخزون الوارد (تاسك 87) */
    expenses: number;
    /** total − expenses: تدفّقٌ نقدي لا ربحٌ محاسبي */
    net: number;
}

export interface SalesReportTypeRow {
    type: 'product' | 'service';
    label: string;
    count: number;
    subtotal: number;
    discounts: number;
    vat: number;
    refunds: number;
    total: number;
}

export interface SalesReportDayRow {
    date: string;
    count: number;
    total: number;
    /** مصروفات اليوم من جدول expenses وحده (تاسك 87) */
    expenses: number;
    /** total − expenses — قد يكون سالباً في يومٍ فيه مصروف بلا مبيعات */
    net: number;
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
