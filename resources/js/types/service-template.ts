import { type BranchService } from './branch-service';

export interface ServiceTemplate {
    id: number;
    name: string;
    description: string | null;
    isActive: boolean;
    branches?: BranchService[];
    createdAt: string;
    updatedAt: string;
}

export interface ServiceTemplateFormData {
    name: string;
    description: string;
    is_active: boolean;
    [key: string]: string | boolean;
}

export interface PaginatedServiceTemplate {
    data: ServiceTemplate[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
