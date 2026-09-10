/** تاسك 93 — أنواع شاشة التوصيل: المزوّدون والشرائح. */

import type { EnumOption } from '.';

export type DeliveryProviderType = 'driver' | 'company';
export type DeliveryZoneType = 'area' | 'distance';

export type { EnumOption };

export interface DeliveryProvider {
    id: number;
    name: string;
    type: EnumOption<DeliveryProviderType>;
    phone: string | null;
    notes: string | null;
    isActive: boolean;
    branchId: number;
    branchName?: string | null;
    canEdit: boolean;
}

export interface DeliveryZone {
    id: number;
    name: string;
    type: EnumOption<DeliveryZoneType>;
    /** الحدّان بالكيلومتر — كلاهما null في صفّ الحي. */
    fromKm: number | null;
    /** null في الشريحة المفتوحة «أكثر من كذا». */
    toKm: number | null;
    /** «0 – 5 كم» أو «أكثر من 20 كم» — يُشتقّ في الخادم، وnull لصفّ الحي. */
    rangeLabel: string | null;
    /** شاملٌ لضريبة القيمة المضافة كسائر أسعار النظام (تاسك 37). */
    price: number;
    sortOrder: number;
    isActive: boolean;
    branchId: number;
    branchName?: string | null;
    canEdit: boolean;
}

export interface ShippingBranchOption {
    id: number;
    name: string;
}
