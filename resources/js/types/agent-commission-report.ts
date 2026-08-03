/** One مندوب's earnings across the filtered range, both invoice kinds merged. */
export interface AgentCommissionRow {
    agentId: number;
    agentName: string;
    invoiceCount: number;
    /** Invoice volume this agent was attached to — per-agent, not branch revenue. */
    sales: number;
    discount: number;
    rebate: number;
    lineCommission: number;
    /** rebate + lineCommission. */
    due: number;
    paid: number;
    outstanding: number;
}

/** One invoice under an agent, for the drill-down. */
export interface AgentCommissionLine {
    agentId: number;
    type: 'service' | 'product';
    invoiceNumber: string | null;
    employeeName: string | null;
    itemsLabel: string | null;
    invoiceTotal: number;
    amount: number;
    isPaid: boolean;
    date: string | null;
}

export interface AgentCommissionTotals {
    agentCount: number;
    invoiceCount: number;
    sales: number;
    discount: number;
    rebate: number;
    lineCommission: number;
    due: number;
    paid: number;
    outstanding: number;
}

export interface AgentCommissionFilters {
    from: string;
    to: string;
    agent: string | null;
    branch: string | null;
}
