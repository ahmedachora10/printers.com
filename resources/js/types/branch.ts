import { type City } from './city';

export interface Branch {
    id: number;
    name: string;
    cityId: number;
    city?: City;
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

export interface PaginatedBranch {
    data: Branch[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
