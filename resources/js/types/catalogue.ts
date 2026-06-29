// Admin (M20) resource shapes — match the API Resources (camelCase).
export interface CatalogCategory {
    id: number;
    nameAr: string;
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
