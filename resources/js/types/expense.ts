export interface Expense {
    id: number;
    expenseCategoryId: number;
    categoryName: string | null;
    qty: number;
    unitPrice: number;
    total: number;
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
