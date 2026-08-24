/** تقرير استهلاك خامات الخدمات — الكميات صافية (الصرف ناقص الإرجاع). */

export interface MaterialsReportTotals {
    netQty: number;
    netCost: number;
    productCount: number;
    invoiceCount: number;
}

export interface MaterialsReportProductRow {
    productId: number;
    name: string;
    unitName: string | null;
    netQty: number;
    netCost: number;
}

export interface MaterialsReportServiceRow {
    /** null لحركات كُتبت قبل نسبة الحركة إلى سطر الخدمة */
    branchServiceId: number | null;
    name: string;
    netQty: number;
    netCost: number;
    invoiceCount: number;
}

export interface MaterialsReportDayRow {
    date: string;
    netQty: number;
    netCost: number;
}

export interface MaterialsReportRow {
    id: number;
    date: string;
    direction: string;
    directionLabel: string;
    productName: string;
    unitName: string | null;
    /** موجبة دائماً — الاتجاه في العمود المجاور */
    qty: number;
    unitCost: number;
    /** موقَّعة: موجبة للصرف وسالبة للإرجاع، فيقرأ مجموع العمود الصافي */
    cost: number;
    serviceName: string | null;
    invoiceId: number | null;
    invoiceNumber: string | null;
    branchName: string | null;
    userName: string | null;
}

export interface MaterialsReportFilters {
    from: string | null;
    to: string | null;
    branch: string | null;
    product: string | null;
    service: string | null;
}
