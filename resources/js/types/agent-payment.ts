export interface AgentOutstanding {
    id: number;
    name: string;
    isActive: boolean;
    outstandingRebate: number;
    outstandingInvoices: number;
}

export interface AgentPaymentRow {
    id: number;
    agentName: string | null;
    periodStart: string;
    periodEnd: string;
    totalInvoices: number;
    totalRebate: number;
    paidBy: string | null;
    paidAt: string | null;
    notes: string | null;
}

export interface PaginatedAgentPayment {
    data: AgentPaymentRow[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
