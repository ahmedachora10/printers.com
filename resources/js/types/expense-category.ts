import { type BranchScoped } from '@/components/branch-scope-field';

/** تاسك 238: branches فارغة = فئة عامة يراها كل فرع. */
export interface ExpenseCategory extends BranchScoped {
    id: number;
    name: string;
    isActive: boolean;
    canEdit: boolean;
}

export interface PaginatedExpenseCategory {
    data: ExpenseCategory[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
