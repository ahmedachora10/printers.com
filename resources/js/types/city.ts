export interface City {
    id: number;
    name: string;
    isActive: boolean;
    createdAt: string;
    updatedAt: string;
}

export interface PaginatedCity {
    data: City[];
    links: Record<string, string | null>;
    meta: Record<string, unknown>;
}
