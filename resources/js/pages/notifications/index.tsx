import { TablePagination } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import notifications from '@/routes/notifications';
import { type BreadcrumbItem } from '@/types';
import { type AppNotification, type PaginatedNotifications } from '@/types/notification';
import { Head, router } from '@inertiajs/react';
import { Bell, Check, CheckCheck, FileText, type LucideIcon, Package, Trash2, Undo2, Wallet } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'الإشعارات', href: '/notifications' }];

const ICON_MAP: Record<string, LucideIcon> = {
    Package,
    FileText,
    Wallet,
    Undo2,
    Bell,
};

interface Props {
    items: PaginatedNotifications;
    unreadCount: number;
}

export default function NotificationsIndex({ items, unreadCount }: Props) {
    const markRead = (item: AppNotification) => {
        router.patch(notifications.read(item.id).url, {}, { preserveScroll: true, preserveState: true });
    };

    const markAllRead = () => {
        router.patch(notifications.readAll().url, {}, { preserveScroll: true, preserveState: true });
    };

    const remove = (item: AppNotification) => {
        if (confirm('هل تريد حذف هذا الإشعار؟')) {
            router.delete(notifications.destroy(item.id).url, { preserveScroll: true, preserveState: true });
        }
    };

    const goTo = (item: AppNotification) => {
        if (!item.isRead) markRead(item);
        if (item.url) router.visit(item.url);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="الإشعارات" />
            <div className="p-6">
                <div className="mb-6 flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <h1 className="text-2xl font-bold">الإشعارات</h1>
                        {unreadCount > 0 && (
                            <span className="flex min-w-5 items-center justify-center rounded-full bg-destructive px-1.5 text-xs font-bold text-white">
                                {unreadCount}
                            </span>
                        )}
                    </div>
                    {unreadCount > 0 && (
                        <Button variant="outline" size="sm" onClick={markAllRead}>
                            <CheckCheck className="size-4" /> تعليم الكل كمقروء
                        </Button>
                    )}
                </div>

                <Card className="flex flex-col overflow-hidden rounded-md">
                    {items.data.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 py-16 text-muted-foreground">
                            <Bell className="size-8 opacity-40" />
                            <span className="text-sm">لا توجد إشعارات</span>
                        </div>
                    ) : (
                        items.data.map((item) => {
                            const Icon = ICON_MAP[item.icon] ?? Bell;
                            return (
                                <div
                                    key={item.id}
                                    className={cn(
                                        'flex items-start gap-3 border-b border-border/50 px-4 py-3.5 transition-colors hover:bg-muted/30',
                                        !item.isRead && 'bg-primary/5',
                                    )}
                                >
                                    <span className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                        <Icon className="size-4" />
                                    </span>
                                    <button onClick={() => goTo(item)} className="min-w-0 flex-1 text-right">
                                        <span className="flex items-center gap-1.5">
                                            {!item.isRead && <span className="size-1.5 shrink-0 rounded-full bg-primary" />}
                                            <span className="text-sm font-medium">{item.title}</span>
                                        </span>
                                        <span className="mt-0.5 block text-sm text-muted-foreground">{item.body}</span>
                                        <span className="mt-1 block text-[11px] text-muted-foreground/70" dir="ltr">
                                            {item.createdAt}
                                        </span>
                                    </button>
                                    <div className="flex shrink-0 items-center gap-1">
                                        {!item.isRead && (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-8 text-muted-foreground"
                                                onClick={() => markRead(item)}
                                                aria-label="تعليم كمقروء"
                                                title="تعليم كمقروء"
                                            >
                                                <Check className="size-4" />
                                            </Button>
                                        )}
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-8 text-muted-foreground hover:text-destructive"
                                            onClick={() => remove(item)}
                                            aria-label="حذف"
                                            title="حذف"
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    </div>
                                </div>
                            );
                        })
                    )}

                    {items.meta.total > 0 && (
                        <TablePagination
                            currentPage={items.meta.current_page}
                            totalPages={items.meta.last_page}
                            totalItems={items.meta.total}
                            pageSize={15}
                            onPageChange={(page) =>
                                router.reload({ data: { page } })
                            }
                        />
                    )}
                </Card>
            </div>
        </AppLayout>
    );
}
