export type AgentType = 'individual' | 'company';
export type AgentDiscountMode = 'discount' | 'rebate';

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
    rate: number;
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
