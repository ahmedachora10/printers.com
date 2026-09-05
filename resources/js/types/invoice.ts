export type InvoiceType = 'product' | 'service';
export type InvoiceStatus = 'paid' | 'partially_paid' | 'due' | 'cancelled' | 'returned';
/** حالة موعد التسليم مقارنةً باليوم — تُحسب على الخادم (DeliveryStatusEnum). */
export type DeliveryStatus = 'delivered' | 'overdue' | 'today' | 'upcoming';

export interface InvoiceLine {
    name: string;
    /** Free-text detail typed at the POS; service lines only. */
    notes: string | null;
    sku: string | null;
    /** الكمية المحاسَب عليها — مساحة بالمتر المربع لسطرٍ مسعّر بالمساحة */
    qty: number;
    unitPrice: number;
    /** ما يقيسه السعر أعلاه: 'sqm' سعر متر مربع، و'linear' سعر متر طولي، و null سعر وحدة/قطعة */
    unitPriceBasis?: 'sqm' | 'linear' | null;
    widthCm: number | null;
    heightCm: number | null;
    /** عدد القطع لسطر المنتج المسعّر بالمتر — الكمية أعلاه مساحتها الإجمالية */
    pieces: number | null;
    discountPct: number;
    subtotal: number;
    commissionAmount: number | null;
    lineAgentName: string | null;
    lineAgentCommissionAmount: number | null;
}

export interface InvoiceBranch {
    name: string | null;
    phone: string | null;
    address: string | null;
    taxNumber: string | null;
    logoUrl: string | null;
}

export interface InvoiceRefund {
    id: number;
    amount: number;
    reason: string;
    stockReversed: boolean;
    userName: string | null;
    createdAt: string | null;
}

/** دفعة واحدة على الفاتورة — عربون أو دفعة لاحقة. */
export interface InvoicePayment {
    id: number;
    amount: number;
    paidAt: string | null;
    paymentMethod: string | null;
    recordedByName: string | null;
    notes: string | null;
    /** رابط إيصال هذه الدفعة (محمي بالصلاحية)، أو null إن لم يُرفق شيء */
    receiptUrl: string | null;
}

export interface InvoiceAgent {
    name: string | null;
    mode: 'discount' | 'rebate';
    rebate: number;
    lineCommission: number;
    discount: number;
    isRebatePaid: boolean;
}

export interface Invoice {
    id: number;
    type: InvoiceType;
    typeLabel: string;
    invoiceNumber: string;
    createdAt: string | null;
    paidAt: string | null;
    status: InvoiceStatus;
    statusLabel: string;
    /** Why a reviewer rejected the invoice — service invoices only. */
    cancellationReason: string | null;
    cancelledByName: string | null;
    cancelledAt: string | null;
    /** موعد تسليم العمل للعميل — فواتير الخدمات فقط */
    deliveryAt: string | null;
    deliveryStatus: DeliveryStatus | null;
    /** ختم تسليم العمل الفعلي ومَن سلّمه (تاسك 31) */
    deliveredAt: string | null;
    deliveredByName: string | null;
    canDeliver: boolean;
    subtotal: number;
    tierDiscountPct: number;
    tierDiscountAmount: number;
    couponDiscount: number;
    agentDiscount: number;
    agents: InvoiceAgent[];
    pointsRedeemed: number;
    pointsDiscount: number;
    vatPct: number;
    vatAmount: number;
    totalAmount: number;
    employeeCommission: number | null;
    customerName: string | null;
    customerPhone: string | null;
    customerTaxNumber: string | null;
    paymentMethod: string | null;
    paymentMethodId: number | null;
    /** ملاحظات على مستوى الفاتورة كاملة — تختلف عن ملاحظات السطر */
    notes: string | null;
    receiptUrl: string | null;
    lines: InvoiceLine[];
    refundedTotal: number;
    refundableRemaining: number;
    isFullyRefunded: boolean;
    /** ما حُصِّل من الفاتورة فعلاً (مجموع الدفعات، أو الإجمالي إن سُدِّدت عند البيع) */
    paidAmount: number;
    /** المتبقي على العميل */
    paymentRemaining: number;
    canRecordPayment: boolean;
    payments?: InvoicePayment[];
    canRefund: boolean;
    canApprovePayment: boolean;
    canEdit: boolean;
    /** المحاسب لا يعدّل الخدمات ولا الأسعار — يبقى له هذان على فاتورة الموظف */
    canEditCustomer: boolean;
    canEditPaymentMethod: boolean;
    canReturn: boolean;
    refunds?: InvoiceRefund[];
    branch: InvoiceBranch;
}

export interface InvoiceListItem {
    id: number;
    type: InvoiceType;
    typeLabel: string;
    serviceNames: string[];
    invoiceNumber: string;
    totalAmount: number;
    /** ما حُصِّل من الفاتورة، والمتبقي على العميل (صفر للملغاة والمرتجعة) */
    paidAmount: number;
    remainingAmount: number;
    status: InvoiceStatus;
    statusLabel: string;
    /** Why a reviewer rejected the invoice — service invoices only. */
    cancellationReason: string | null;
    customerId: number | null;
    customerName: string | null;
    customerPhone: string | null;
    customerTaxNumber: string | null;
    employeeName: string | null;
    /** Super-admins only — omitted from the payload for every other role. */
    branchName?: string | null;
    createdAt: string;
    /** موعد تسليم العمل للعميل — فواتير الخدمات فقط */
    deliveryAt: string | null;
    deliveryStatus: DeliveryStatus | null;
    /** ختم تسليم العمل الفعلي (تاسك 31) */
    deliveredAt: string | null;
    canDeliver: boolean;
    canEdit: boolean;
    canReturn: boolean;
    /** Owner of an invoice that is already returned — the control shows, disabled. */
    returnLocked: boolean;
    canEditCustomer: boolean;
}

export interface PaginatedInvoice {
    data: InvoiceListItem[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}

export interface InvoiceFilters {
    search?: string;
    type?: string;
    status?: string;
    date_from?: string;
    date_to?: string;
    branch_id?: string;
    /** 'today' | 'overdue' | 'delivered' — تصفية حسب موعد التسليم */
    delivery?: string;
}
