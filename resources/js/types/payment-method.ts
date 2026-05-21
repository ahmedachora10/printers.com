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
