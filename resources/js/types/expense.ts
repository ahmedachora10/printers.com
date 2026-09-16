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
    paidFromLabel: string;
    supplierName: string | null;
    receiptReference: string | null;
    comment: string | null;
    date: string;
    dateLabel: string;
    userName: string | null;
    createdAt: string;
}

export interface PaginatedExpense {
    data: Expense[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
