export interface Product {
    id: number;
    sku: string;
    name: string;
    categoryId: number;
    categoryName: string;
    unitId: number;
    unitName: string;
    costPrice: number;
    sellingPrice: number;
    minStockLevel: number;
    currentStock: number;
    barcode: string | null;
    isActive: boolean;
    valuation: number;
}

export interface PaginatedProduct {
    data: Product[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
