export interface SalesReportTotals {
    invoiceCount: number;
    subtotal: number;
    discounts: number;
    vat: number;
    /** جملة رسوم التوصيل — داخلةٌ في الإجمالي ومعروضةٌ مستقلّةً (تاسك 93) */
    shipping: number;
    /** جملة ما رُدّ للعملاء، موجبةً — و`total` صافٍ منها */
    refunds: number;
    total: number;
    /** ما حُصِّل بطرقٍ نقدية (is_cash) — صافٍ من المرتجعات النقدية (تاسك 97) */
    cash: number;
    /** مصروفات المدى من جدول expenses وحده — لا تشمل قيمة المخزون الوارد (تاسك 87) */
    expenses: number;
    /** cash − expenses: المصروفات تُطرح من النقد وحده (تاسك 97) */
    cashRemaining: number;
}

export interface SalesReportTypeRow {
    type: 'product' | 'service';
    label: string;
    count: number;
    subtotal: number;
    discounts: number;
    vat: number;
    /** جملة رسوم التوصيل — داخلةٌ في الإجمالي ومعروضةٌ مستقلّةً (تاسك 93) */
    shipping: number;
    refunds: number;
    total: number;
}

export interface SalesReportDayRow {
    date: string;
    count: number;
    total: number;
    /** ما حُصِّل نقداً في اليوم (تاسك 97) */
    cash: number;
    /** مصروفات اليوم من جدول expenses وحده (تاسك 87) */
    expenses: number;
    /** cash − expenses — قد يكون سالباً في يومٍ فيه مصروف بلا تحصيل نقدي */
    cashRemaining: number;
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
    /** طريقة نقدية — عليها تُطرح المصروفات (تاسك 97) */
    isCash: boolean;
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
