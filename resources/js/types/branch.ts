import { type City } from './city';

export interface BranchAdmin {
    id: number;
    name: string;
}

export interface Branch {
    id: number;
    name: string;
    cityId: number;
    city?: City;
    ownerId: number | null;
    owner?: BranchAdmin | null;
    phone: string | null;
    address: string | null;
    businessType: string | null;
    commercialRegNo: string | null;
    taxNumber: string | null;
    vatRateOverride: number;
    isActive: boolean;
    logoUrl: string;
    createdAt: string;
    updatedAt: string;
}

export interface BranchFormData {
    name: string;
    city_id: string;
    phone: string;
    address: string;
    business_type: string;
    commercial_reg_no: string;
    tax_number: string;
    owner_id: string;
    vat_rate_override: number;
    is_active: boolean;
    logo: File | null;
    [key: string]: string | number | boolean | File | null;
}

/**
 * The subset of BranchFormData a branch-admin may edit for their own branch
 * from the settings screen — no `owner_id`, no `is_active`.
 */
export interface BranchProfileFormData {
    name: string;
    city_id: string;
    phone: string;
    address: string;
    business_type: string;
    commercial_reg_no: string;
    tax_number: string;
    vat_rate_override: number;
    logo: File | null;
    [key: string]: string | number | boolean | File | null;
}

export interface PaginatedBranch {
    data: Branch[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
