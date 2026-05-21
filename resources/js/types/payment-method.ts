export interface PaymentMethod {
    id: number;
    name: string;
    branchId: number;
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
