export interface PaymentMethod {
    id: number;
    name: string;
    isActive: boolean;
}

export interface AppSettingsGeneralData {
    appName: string | null;
    defaultVatPct: string | null;
    vatOverridePct: string | null;
}

export interface AppSettingsInventoryData {
    minStockAlertThreshold: string | null;
}

export interface AppSettingsLoyaltyData {
    isActive: boolean;
    earningRate: number;
    redemptionRate: number;
    minRedemptionPoints: number;
    bronzeThreshold: number;
    silverThreshold: number;
    goldThreshold: number;
    bronzeDiscountPct: number;
    silverDiscountPct: number;
    goldDiscountPct: number;
}
