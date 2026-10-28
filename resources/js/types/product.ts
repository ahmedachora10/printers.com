import { type PriceTier } from '@/lib/invoice';

export interface Product {
    id: number;
    sku: string;
    name: string;
    categoryId: number;
    categoryName: string;
    unitId: number;
    unitName: string;
    /** منتج يُباع ويُخصم من المخزون بالمتر المربع — سعره سعرُ المتر (تاسك 51) */
    isSqm: boolean;
    costPrice: number;
    sellingPrice: number;
    /** تاسك 241: شرائح السعر حسب الكمية */
    priceTiers: PriceTier[];
    minStockLevel: number;
    currentStock: number;
    barcode: string | null;
    isActive: boolean;
    /** يُباع أيضاً من شاشة الموظف كخدمة */
    soldAsService: boolean;
    valuation: number;
}

export interface PaginatedProduct {
    data: Product[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
