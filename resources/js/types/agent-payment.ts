/**
 * One settlement row: an agent's unpaid balance *in one branch*. A multi-branch
 * agent yields one row per branch, since each branch is paid separately.
 */
export interface AgentOutstanding {
    id: number;
    name: string;
    isActive: boolean;
    branchId: number;
    branchName: string;
    outstandingRebate: number;
    outstandingInvoices: number;
}

export interface AgentPaymentRow {
    id: number;
    agentName: string | null;
    branchName: string | null;
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
