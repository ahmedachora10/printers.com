export interface PaymentMethod {
    id: number;
    name: string;
    isActive: boolean;
    requiresAttachment: boolean;
}

export interface AppSettingsGeneralData {
    appName: string | null;
    defaultVatPct: string | null;
}

export interface AppSettingsInventoryData {
    minStockAlertThreshold: string | null;
}

export interface AppSettingsLoyaltyData {
    isActive: boolean;
    earningRate: number;
    redemptionRate: number;
    minRedemptionPoints: number;
    /** null = النقاط لا تنتهي صلاحيتها */
    expiryMonths: number | null;
    bronzeThreshold: number;
    silverThreshold: number;
    goldThreshold: number;
    bronzeDiscountPct: number;
    silverDiscountPct: number;
    goldDiscountPct: number;
}
