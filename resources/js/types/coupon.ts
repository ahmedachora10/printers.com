export type CouponDiscountType = 'percentage' | 'fixed';

export interface Coupon {
    id: number;
    code: string;
    branchId: number;
    discountType: {
        value: CouponDiscountType;
        label: string;
    };
    discountValue: number;
    capacity: number | null;
    usedCount: number;
    remainingCapacity: number | null;
    expiresAt: string | null;
    isActive: boolean;
    createdAt: string;
}

export interface PaginatedCoupon {
    data: Coupon[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}

export interface CouponValidationResult {
    valid: boolean;
    type?: CouponDiscountType;
    value?: number;
    remaining_capacity?: number | null;
}
