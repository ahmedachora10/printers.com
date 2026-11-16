import { type BranchScoped } from '@/components/branch-scope-field';

/** تاسك 59/242: branches فارغة = طريقة عامة يرثها كل فرع. */
export interface PaymentMethod extends BranchScoped {
    id: number;
    name: string;
    isActive: boolean;
    requiresAttachment: boolean;
    /** طريقة نقدية — تقرير المبيعات يطرح المصروفات من هذه الطرق وحدها (تاسك 97) */
    isCash: boolean;
    /** طريقة شبكة — موازنتها تُدخَل يدوياً لكل جهاز في مطابقة الحسابات (تاسك 121) */
    isNetwork: boolean;
    /** هل يملك المستخدم الحالي تحريرها — يقرّره الخادم لا الدور في الواجهة. */
    canEdit: boolean;
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
