export interface Supplier {
    id: number;
    name: string;
    phone: string | null;
    email: string | null;
    notes: string | null;
    isActive: boolean;
    purchaseOrderCount?: number;
}

export interface PaginatedSupplier {
    data: Supplier[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
