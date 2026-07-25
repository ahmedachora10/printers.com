import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';

// Normalize either an absolute URL (from Laravel's route() helper) or a
// relative path down to just its pathname, so the active check is not broken
// by host or query-string differences.
function toPath(url: string): string {
    try {
        return new URL(url, window.location.origin).pathname;
    } catch {
        return url.split('?')[0];
    }
}

// Active when the current path equals the item path, or is a sub-page of it
// (e.g. /branches/5 highlights the /branches item). The trailing-slash check
// keeps /branches from matching an unrelated /branches-archive.
function isActivePath(itemUrl: string, currentPath: string): boolean {
    const itemPath = toPath(itemUrl);
    return currentPath === itemPath || currentPath.startsWith(itemPath + '/');
}

type NavGroup = { label: string | null; items: NavItem[] };

export function NavMain({ groups = [] }: { groups: NavGroup[] }) {
    const page = usePage();
    const currentPath = toPath(page.url);
    return (
        <>
            {groups.map((group, index) => (
                <SidebarGroup key={group.label ?? `group-${index}`} className="px-2 py-0">
                    {group.label && <SidebarGroupLabel>{group.label}</SidebarGroupLabel>}
                    <SidebarMenu>
                        {group.items.map((item) => (
                            <SidebarMenuItem key={item.title}>
                                <SidebarMenuButton asChild isActive={isActivePath(item.url, currentPath)}>
                                    <Link href={item.url} prefetch>
                                        {item.icon && <item.icon />}
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
