export interface DashboardKpis {
    todaySales: number | null;
    monthSales: number | null;
    outstandingDue: number | null;
    pendingCommissions: number | null;
    lowStockCount: number | null;
}

export interface DashboardTrendPoint {
    date: string;
    product: number;
    service: number;
}

export interface DashboardSalesByType {
    product: number;
    service: number;
}

export interface DashboardPaymentMethod {
    name: string;
    total: number;
}

export interface DashboardTopService {
    name: string;
    count: number;
    total: number;
}

export interface DashboardIncentive {
    target: number;
    achieved: number;
    pct: number;
    bonus: number;
    status: string;
}

export interface DashboardRecentInvoice {
    id: number;
    type: 'product' | 'service';
    invoiceNumber: string;
    customerName: string | null;
    total: number;
    status: 'paid' | 'due' | 'cancelled' | 'returned';
    createdAt: string | null;
}

export interface DashboardScope {
    isSuper: boolean;
    isEmployee: boolean;
    userName: string;
    trendDays: number;
}
