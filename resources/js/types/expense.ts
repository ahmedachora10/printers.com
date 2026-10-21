export type ExpenseSource = 'cash_drawer' | 'company_transfer';

export interface ExpenseAttachment {
    id: number;
    name: string;
    url: string;
}

/** تاسك 187 — خيار حساب بنكي؛ يُصفّى بفرع المصروف كالفئات. */
export interface BankAccountOption {
    id: number;
    name: string;
    branchId: number;
}

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
    /** تاسك 187 — حساب التحويل؛ null للنقدي وللتحويلات القديمة */
    bankAccountId: number | null;
    bankAccountName: string | null;
    supplierName: string | null;
    /** تاسك 198 — غير null = مصروف تسوية توصيل: السائق يتغيّر وربط الفاتورة ثابت */
    deliveryProviderId: number | null;
    receiptReference: string | null;
    comment: string | null;
    /** تاسك 112/187 — المرفقات عبر مسارٍ مفوَّض، والفواتير المربوطة بحصّة كلٍّ منها */
    attachments: ExpenseAttachment[];
    invoices: { id: number; invoiceNumber: string; amount: number }[];
    date: string;
    dateLabel: string;
    userName: string | null;
    createdAt: string;
    /** تاسك 113 — null = غير معتمد */
    approvedAt: string | null;
    approvedByName: string | null;
    /** تاسك 157 — طلب موظف؛ pendingRequest = لم يقبله المحاسب بعد فلا يُحسب. */
    requestedByName: string | null;
    pendingRequest: boolean;
    canAccept: boolean;
    canApprove: boolean;
    canUnapprove: boolean;
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
