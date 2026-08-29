export type AgentType = 'individual' | 'company';
export type AgentDiscountMode = 'discount' | 'rebate';
export type AgentDiscountType = 'percentage' | 'fixed';

/**
 * The terms an agent works on inside one branch. An agent may be linked to
 * several branches, each with its own mode and rate — this link, not `branchId`,
 * decides where the agent may be put on an invoice.
 */
export interface AgentBranchTerms {
    branchId: number;
    branchName: string;
    discountMode: AgentDiscountMode | null;
    discountType: AgentDiscountType | null;
    rate: number;
    /** تاسك 69: طرح تكلفة خامات السطر من قاعدة العمولة بالنسبة. */
    deductMaterials: boolean;
}

export interface Agent {
    id: number;
    name: string;
    username: string;
    email: string | null;
    phone: string | null;
    branchId: number | null;
    branchName: string | null;
    isActive: boolean;
    agentType: {
        value: AgentType;
        label: string;
    } | null;
    discountMode: {
        value: AgentDiscountMode;
        label: string;
    } | null;
    discountType: {
        value: AgentDiscountType;
        label: string;
    } | null;
    rate: number;
    branches: AgentBranchTerms[];
    commercialRegNo: string | null;
    createdAt: string;
}

export interface PaginatedAgent {
    data: Agent[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}

export interface EnumOption {
    value: string;
    label: string;
}
