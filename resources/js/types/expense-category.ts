export interface ExpenseCategory {
    id: number;
    name: string;
    isActive: boolean;
    /** تاسك 238: فروع الفئة — فارغة = عامة يراها كل فرع. */
    branches?: { id: number; name: string }[];
    canEdit: boolean;
}

export interface PaginatedExpenseCategory {
    data: ExpenseCategory[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
