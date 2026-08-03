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
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    notifications?: NotificationsShared;
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
