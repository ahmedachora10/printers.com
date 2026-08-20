// Admin (M20) resource shapes — match the API Resources (camelCase).
export interface CatalogCategory {
    id: number;
    nameAr: string;
    /** null = general row every branch inherits (تاسك 47). */
    branchId: number | null;
    branchName?: string | null;
    sortOrder: number;
    isActive: boolean;
    imageUrl: string | null;
    subcategoriesCount?: number;
    createdAt: string;
    updatedAt: string;
}

export interface CatalogSubcategory {
    id: number;
    nameAr: string;
    categoryId: number;
    /** null = general row every branch inherits (تاسك 47). */
    branchId: number | null;
    branchName?: string | null;
    sortOrder: number;
    isActive: boolean;
    imageUrl: string | null;
    pricesCount?: number;
    createdAt: string;
    updatedAt: string;
}

export interface CatalogPrice {
    id: number;
    subcategoryId: number;
    /** null = general price shared by every branch (تاسك 47). */
    branchId: number | null;
    branchName?: string | null;
    name: string;
    minPrice: number;
    maxPrice: number;
    basePrice: number;
    sortOrder: number;
    isActive: boolean;
    createdAt: string;
    updatedAt: string;
}

export interface Paginated<T> {
    data: T[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}

// Public catalogue (M19) tree shapes.
export interface PublicPrice {
    id: number;
    name: string;
    minPrice: number;
    maxPrice: number;
    basePrice: number;
    /** true when this branch overrides the general price (تاسك 47). */
    isBranchPrice: boolean;
}

/** Branch picker option on the catalogue and price-list screens. */
export interface CatalogueBranchOption {
    id: number;
    name: string;
}

export interface PublicSubcategory {
    id: number;
    nameAr: string;
    imageUrl: string | null;
    prices: PublicPrice[];
}

export interface PublicCategory {
    id: number;
    nameAr: string;
    imageUrl: string | null;
    subcategories: PublicSubcategory[];
}
