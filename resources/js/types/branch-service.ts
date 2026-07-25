export type ServicePricingType = 'unit' | 'sqm';

export interface BranchService {
    id: number;
    branchId: number;
    branchName: string | null;
    serviceTemplateId: number;
    serviceTemplateName: string | null;
    baseCommissionPct: number;
    maxDiscountPct: number;
    pricingType: ServicePricingType;
    pricePerSqm: number;
    agentCommissionPerSqm: number;
    isTahazir: boolean;
    isActive: boolean;
    createdAt: string | null;
    updatedAt: string | null;
}

export interface BranchServiceFormData {
    service_template_id: number;
    branch_id: number;
    base_commission_pct: number;
    max_discount_pct: number;
    pricing_type: ServicePricingType;
    price_per_sqm: number;
    agent_commission_per_sqm: number;
    is_tahazir: boolean;
    is_active: boolean;
    [key: string]: number | boolean | ServicePricingType;
}

export interface BranchServiceUpdateData {
    base_commission_pct: number;
    max_discount_pct: number;
    pricing_type: ServicePricingType;
    price_per_sqm: number;
    agent_commission_per_sqm: number;
    is_tahazir: boolean;
    is_active: boolean;
    [key: string]: number | boolean | ServicePricingType;
}
