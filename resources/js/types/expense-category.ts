export interface ExpenseCategory {
    id: number;
    name: string;
    isActive: boolean;
}

export interface PaginatedExpenseCategory {
    data: ExpenseCategory[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
