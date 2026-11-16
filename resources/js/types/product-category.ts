import { type BranchScoped } from '@/components/branch-scope-field';

/** تاسك 237: branches فارغة = فئة عامة يراها كل فرع. */
export interface ProductCategory extends BranchScoped {
    id: number;
    name: string;
    isActive: boolean;
    canEdit: boolean;
}

export interface PaginatedProductCategory {
    data: ProductCategory[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
