export interface AppNotification {
    id: string;
    type: string;
    title: string;
    body: string;
    url: string | null;
    icon: string;
    isRead: boolean;
    createdAt?: string;
    timeAgo?: string;
}

export interface NotificationsShared {
    unreadCount: number;
    items: AppNotification[];
}

export interface PaginatedNotifications {
    data: AppNotification[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
}
