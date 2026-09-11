export interface ExpenseCategory {
    id: number;
    name: string;
    isActive: boolean;
    /** null = فئة عامة يراها كل فرع (تاسك 102). */
    branchId: number | null;
    branchName?: string | null;
    canEdit: boolean;
}

export interface PaginatedExpenseCategory {
    data: ExpenseCategory[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
