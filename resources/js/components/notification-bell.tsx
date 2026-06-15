import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import notifications from '@/routes/notifications';
import { type SharedData } from '@/types';
import { type AppNotification } from '@/types/notification';
import { Link, router, usePage, usePoll } from '@inertiajs/react';
import { Bell, FileText, type LucideIcon, Package, Undo2, Wallet } from 'lucide-react';

const ICON_MAP: Record<string, LucideIcon> = {
    Package,
    FileText,
    Wallet,
    Undo2,
    Bell,
};

export function NotificationBell() {
    const { notifications: shared } = usePage<SharedData>().props;

    // Keep the bell fresh without a full navigation; throttled when tab is hidden.
    usePoll(30000, { only: ['notifications'] });

    const unreadCount = shared?.unreadCount ?? 0;
    const items = shared?.items ?? [];

    const handleItemClick = (item: AppNotification) => {
        if (!item.isRead) {
            router.patch(notifications.read(item.id).url, {}, { preserveScroll: true, preserveState: true, only: ['notifications'] });
        }
        if (item.url) {
            router.visit(item.url);
        }
    };

    const markAllRead = () => {
        router.patch(notifications.readAll().url, {}, { preserveScroll: true, preserveState: true, only: ['notifications'] });
    };

    return (
        <DropdownMenu dir='rtl'>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="relative size-9" aria-label="الإشعارات">
                    <Bell className="size-5" />
                    {unreadCount > 0 && (
                        <span className="absolute -top-0.5 -left-0.5 flex min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-bold leading-4 text-white">
                            {unreadCount > 99 ? '99+' : unreadCount}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-80 p-0">
                <div className="flex items-center justify-between border-b px-3 py-2">
                    <span className="text-sm font-semibold">الإشعارات</span>
                    {unreadCount > 0 && (
                        <button onClick={markAllRead} className="text-xs text-muted-foreground hover:text-foreground hover:underline">
                            تعليم الكل كمقروء
                        </button>
                    )}
                </div>

                <div className="max-h-80 overflow-y-auto">
                    {items.length === 0 ? (
                        <p className="px-3 py-8 text-center text-sm text-muted-foreground">لا توجد إشعارات</p>
                    ) : (
                        items.map((item) => {
                            const Icon = ICON_MAP[item.icon] ?? Bell;
                            return (
                                <button
                                    key={item.id}
                                    onClick={() => handleItemClick(item)}
                                    className={cn(
                                        'flex w-full items-start gap-3 border-b px-3 py-2.5 text-right transition-colors hover:bg-muted/50',
                                        !item.isRead && 'bg-primary/5',
                                    )}
                                >
                                    <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                        <Icon className="size-4" />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-1.5">
                                            {!item.isRead && <span className="size-1.5 shrink-0 rounded-full bg-primary" />}
                                            <span className="truncate text-sm font-medium">{item.title}</span>
                                        </span>
                                        <span className="mt-0.5 block text-xs text-muted-foreground">{item.body}</span>
                                        {item.timeAgo && <span className="mt-1 block text-[11px] text-muted-foreground/70">{item.timeAgo}</span>}
                                    </span>
                                </button>
                            );
                        })
                    )}
                </div>

                <div className="border-t px-3 py-2 text-center">
                    <Link href={notifications.index().url} className="text-xs font-medium text-primary hover:underline">
                        عرض كل الإشعارات
                    </Link>
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
