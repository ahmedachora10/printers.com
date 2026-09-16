export type ExpenseSource = 'cash_drawer' | 'company_transfer';

export interface Expense {
    id: number;
    branchId: number;
    expenseCategoryId: number;
    categoryName: string | null;
    qty: number;
    unitPrice: number;
    total: number;
    /** تاسك 110 — النقدي وحده يُطرح من «المتبقي من النقد» */
    paidFrom: ExpenseSource;
    supplierName: string | null;
    receiptReference: string | null;
    comment: string | null;
    /** تاسك 112 — المرفق عبر مسارٍ مفوَّض، والطلب المربوط */
    attachmentUrl: string | null;
    serviceInvoiceId: number | null;
    invoiceNumber: string | null;
    date: string;
    dateLabel: string;
    userName: string | null;
    createdAt: string;
    /** تاسك 113 — null = غير معتمد */
    approvedAt: string | null;
    approvedByName: string | null;
    canApprove: boolean;
    canUpdate: boolean;
    canDelete: boolean;
    /** تعديلات ما بعد الاعتماد، الأحدث أولاً */
    history: { id: number; byName: string | null; at: string; old: Record<string, unknown>; new: Record<string, unknown> }[];
}

export interface PaginatedExpense {
    data: Expense[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
