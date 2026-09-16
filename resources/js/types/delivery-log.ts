/** تاسك 93 — كشف توصيلات اليوم. تاسك 111: وتسوية أجر السائق لكل طلب. */

export interface DeliveryLogRow {
    id: number;
    invoiceNumber: string;
    createdAt: string | null;
    customerName: string | null;
    customerPhone: string | null;
    /** لقطة العنوان وقت الفوترة */
    address: string | null;
    zoneName: string | null;
    providerId: number | null;
    providerName: string | null;
    providerPhone: string | null;
    shippingFee: number;
    branchName: string | null;
    statusLabel: string;
    branchId: number;
    /** تاسك 111 — null = غير مسوّاة. مستقلةٌ عن statusLabel (سداد العميل). */
    settlement: {
        expenseId: number;
        amount: number;
        paidFromLabel: string;
        settledByName: string | null;
        settledAt: string | null;
    } | null;
}

/** صفٌّ لكل سائق على المدى كلّه — لا على الصفحة المعروضة. */
export interface DeliveryLogProviderRow {
    providerId: number;
    providerName: string | null;
    providerPhone: string | null;
    deliveries: number;
    fees: number;
}

export interface DeliveryLogTotals {
    deliveries: number;
    fees: number;
    providers: number;
}

export interface DeliveryLogFilters {
    from: string | null;
    to: string | null;
    branch: string | null;
    provider: string | null;
    settlement: 'settled' | 'unsettled' | null;
}
