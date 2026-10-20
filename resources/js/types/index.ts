import { LucideIcon } from 'lucide-react';
import { type NotificationsShared } from './notification';

export interface Impersonating {
    active: boolean;
    viewingName: string | null;
}

export interface AuthBranch {
    name: string;
    logoUrl: string | null;
}

export interface Auth {
    user: User;
    role?: string;
    /** The branch this user works out of — null for a super-admin. */
    branch?: AuthBranch | null;
    impersonating?: Impersonating | null;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** تاسك 196 — مفتاحه في pendingCounts، ووجهة البند ما دام عليه معلّق. */
    pending?: { key: string; url: string };
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    notifications?: NotificationsShared;
    /** تاسك 196 — عدد المعلّق في كل طابور يقرّر فيه المستخدم. */
    pendingCounts?: Record<string, number>;
    /** تاسك 189 — أقدم يومٍ يبلغه المحاسب في التقارير (YYYY-MM-DD)؛ null لغيره، وغائبٌ خارج التقارير. */
    reportMinDate?: string | null;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

export interface Agent {
    id: number;
    name: string;
}   

/** ترويسة الترقيم التي تُصاحب كل جدول مرقَّم قادم من BuildsPagedProps. */
export interface PageMeta {
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
    /** مدى الصفحة كما حسبه الخادم (تاسك 78) — null على صفحةٍ بلا نتائج. */
    from: number | null;
    to: number | null;
}

/** خيارُ enum قادمٌ من الخادم: القيمة المخزَّنة وتسميتها العربية. */
export interface EnumOption<T extends string = string> {
    value: T;
    label: string;
}

/** جدولٌ مرقَّم: صفوف الصفحة الحالية وترويستها. */
export interface Paginated<T> {
    data: T[];
    meta: PageMeta;
}
