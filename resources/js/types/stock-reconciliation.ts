export interface StockReconciliationLine {
    id: number;
    productId: number;
    productName: string | null;
    sku: string | null;
    systemQty: number;
    physicalQty: number;
    variance: number;
    movementId: number | null;
}

export interface StockReconciliation {
    id: number;
    branchName?: string;
    initiatedByName: string | null;
    isCompleted: boolean;
    statusLabel: string;
    notes: string | null;
    linesCount?: number;
    variantLinesCount?: number;
    totalVariance?: number;
    createdAt: string | null;
    completedAt: string | null;
    lines?: StockReconciliationLine[];
}

export interface PaginatedStockReconciliation {
    data: StockReconciliation[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}

export const RECONCILIATION_STATUS_BADGE = {
    inProgress: 'border-amber-200 bg-amber-50 text-amber-800',
    completed: 'border-green-200 bg-green-50 text-green-700',
} as const;

export const reconciliationBadgeClass = (item: Pick<StockReconciliation, 'isCompleted'>) =>
    item.isCompleted ? RECONCILIATION_STATUS_BADGE.completed : RECONCILIATION_STATUS_BADGE.inProgress;
