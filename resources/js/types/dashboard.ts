export interface DashboardKpis {
    todaySales: number;
    monthSales: number;
    outstandingDue: number;
    pendingCommissions: number;
    lowStockCount: number | null;
}

export interface DashboardRecentInvoice {
    id: number;
    type: 'product' | 'service';
    invoiceNumber: string;
    customerName: string | null;
    total: number;
    status: 'paid' | 'due' | 'cancelled';
    createdAt: string | null;
}

export interface DashboardTopService {
    name: string;
    count: number;
    total: number;
}

export interface DashboardScope {
    isSuper: boolean;
    isEmployee: boolean;
    userName: string;
}
