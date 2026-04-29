export interface BranchService {
    id: number;
    branchId: number;
    branchName: string | null;
    serviceTemplateId: number;
    baseCommissionPct: number;
    maxDiscountPct: number;
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
    is_tahazir: boolean;
    is_active: boolean;
    [key: string]: number | boolean;
}

export interface BranchServiceUpdateData {
    base_commission_pct: number;
    max_discount_pct: number;
    is_tahazir: boolean;
    is_active: boolean;
    [key: string]: number | boolean;
}
