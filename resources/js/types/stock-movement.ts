export type StockMovementType =
    | 'purchase_in'
    | 'sale_out'
    | 'return_in'
    | 'adjustment_in'
    | 'adjustment_out'
    | 'opening_stock';

/** Manual types staff may record from the adjustment modal. */
export const MANUAL_MOVEMENT_TYPES: { value: StockMovementType; label: string }[] = [
    { value: 'opening_stock', label: 'رصيد افتتاحي' },
    { value: 'adjustment_in', label: 'تسوية إضافة' },
    { value: 'adjustment_out', label: 'تسوية خصم' },
];

export interface StockMovement {
    id: number;
    productId: number;
    productName: string | null;
    sku: string | null;
    type: StockMovementType;
    typeLabel: string;
    qty: number;
    unitCost: number | null;
    notes: string | null;
    createdByName: string | null;
    createdAt: string | null;
}

export interface PaginatedStockMovement {
    data: StockMovement[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
