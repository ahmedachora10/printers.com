import type { InvoiceStatus } from './invoice';

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

/** ما حُسم على الموظف: حسومات الشهر الجاري، ومجملها، وآخر سببٍ حُسم لأجله. */
export interface DashboardDeductions {
    monthTotal: number;
    monthCount: number;
    total: number;
    lastReason: string | null;
    lastDate: string | null;
}

export interface DashboardRecentInvoice {
    id: number;
    type: 'product' | 'service';
    invoiceNumber: string;
    customerName: string | null;
    total: number;
    // نفس اتحاد حالات الفاتورة لا نسخة منه: أي حالة جديدة في InvoiceStatusEnum
    // تكسر البناء هنا بدل أن تسقط الصفحة وقت التشغيل.
    status: InvoiceStatus;
    createdAt: string | null;
}

export interface DashboardScope {
    isSuper: boolean;
    isEmployee: boolean;
    userName: string;
    trendDays: number;
}
