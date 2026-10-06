import { type AppNotification } from '@/types/notification';
import { router } from '@inertiajs/react';

/** A ready ZIP is a file download, not an Inertia page — navigate the browser to it. */
export function openNotification(item: AppNotification) {
    if (!item.url) return;
    if (item.type === 'media_zip_ready') window.location.href = item.url;
    else router.visit(item.url);
}
