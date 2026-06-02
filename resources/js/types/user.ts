export interface ManagedUser {
    id: number;
    name: string;
    username: string;
    email: string;
    phone: string | null;
    branchId: number | null;
    branchName: string | null;
    role: string | null;
    roleLabel: string | null;
    salary: number;
    baseCommissionPct: number;
    referralCommissionPct: number;
    joinedDate: string | null;
    isActive: boolean;
    createdAt: string;
}

export interface RoleOption {
    value: string;
    label: string;
}

export interface BranchOption {
    id: number;
    name: string;
}

export interface PaginatedUser {
    data: ManagedUser[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
