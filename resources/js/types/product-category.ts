export interface ProductCategory {
    id: number;
    name: string;
    isActive: boolean;
}

export interface PaginatedProductCategory {
    data: ProductCategory[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
