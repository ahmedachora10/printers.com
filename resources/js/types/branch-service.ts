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
    /** ready-made detail phrases shown as the POS placeholder for this service */
    noteExamples: string[];
    isTahazir: boolean;
    /** هل للخدمة خامات، وتكلفتها الافتراضية للوحدة الواحدة */
    hasMaterials: boolean;
    materialsCost: number;
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
    note_examples: string[];
    is_tahazir: boolean;
    has_materials: boolean;
    materials_cost: number;
    is_active: boolean;
    [key: string]: number | boolean | ServicePricingType | string[];
}

export interface BranchServiceUpdateData {
    base_commission_pct: number;
    max_discount_pct: number;
    pricing_type: ServicePricingType;
    price_per_sqm: number;
    agent_commission_per_sqm: number;
    note_examples: string[];
    is_tahazir: boolean;
    has_materials: boolean;
    materials_cost: number;
    is_active: boolean;
    [key: string]: number | boolean | ServicePricingType | string[];
}
