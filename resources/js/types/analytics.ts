export interface AnalyticsTrendPoint {
    date: string;
    product: number;
    service: number;
}

export interface AnalyticsSalesByType {
    product: number;
    service: number;
}

export interface AnalyticsRankedRow {
    name: string;
    count: number;
    total: number;
}

/** تاسك 114 — ساعة إنشاء الفاتورة (0..23، بتوقيت الرياض) */
export interface AnalyticsHourRow {
    hour: number;
    count: number;
    total: number;
}

export interface AnalyticsTierSlice {
    tier: 'none' | 'bronze' | 'silver' | 'gold';
    count: number;
}

export interface AnalyticsPointsMonth {
    month: string;
    earned: number;
    redeemed: number;
}

export interface AnalyticsLoyalty {
    tierDistribution: AnalyticsTierSlice[];
    pointsMonthly: AnalyticsPointsMonth[];
}

export interface AnalyticsFilters {
    from: string;
    to: string;
    branch: string | null;
}
