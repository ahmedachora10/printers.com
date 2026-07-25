import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import {
    SidebarGroup,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, type LucideIcon } from 'lucide-react';
import { useState } from 'react';

type NavGroup = { label: string | null; icon?: LucideIcon; items: NavItem[] };

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

export function NavMain({ groups = [] }: { groups: NavGroup[] }) {
    const page = usePage();
    const currentPath = toPath(page.url);

    return (
        <>
            {groups.map((group, index) => (
                <SidebarGroup key={group.label ?? `flat-${index}`} className="px-2 py-0">
                    <SidebarMenu>
                        {group.label === null
                            ? // Top section: plain links, no dropdown.
                              group.items.map((item) => (
                                  <SidebarMenuItem key={item.title}>
                                      <SidebarMenuButton asChild isActive={isActivePath(item.url, currentPath)} tooltip={item.title}>
                                          <Link href={item.url} prefetch>
                                              {item.icon && <item.icon />}
                                              <span>{item.title}</span>
                                          </Link>
                                      </SidebarMenuButton>
                                  </SidebarMenuItem>
                              ))
                            : <NavGroupDropdown group={group} currentPath={currentPath} />}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}

function NavGroupDropdown({ group, currentPath }: { group: NavGroup; currentPath: string }) {
    const { state, isMobile } = useSidebar();
    const Icon = group.icon;
    const label = group.label ?? '';
    const containsActive = group.items.some((item) => isActivePath(item.url, currentPath));

    // Remember each dropdown's open/closed state across navigations (the sidebar
    // remounts on every page load). Fall back to auto-opening the group that
    // holds the active page the first time it is seen.
    const storageKey = `sidebar-group:${label}`;
    const [open, setOpen] = useState<boolean>(() => {
        const saved = typeof window !== 'undefined' ? sessionStorage.getItem(storageKey) : null;
        return saved !== null ? saved === '1' : containsActive;
    });

    const handleOpenChange = (next: boolean) => {
        setOpen(next);
        sessionStorage.setItem(storageKey, next ? '1' : '0');
    };

    // Icon-only sidebar: children can't render inline (they are CSS-hidden), so
    // surface them as a hover/click flyout instead.
    if (state === 'collapsed' && !isMobile) {
        return (
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton tooltip={label} isActive={containsActive}>
                            {Icon && <Icon />}
                            <span>{label}</span>
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent side="left" align="start" className="min-w-48">
                        <DropdownMenuLabel>{label}</DropdownMenuLabel>
                        {group.items.map((item) => (
                            <DropdownMenuItem key={item.title} asChild>
                                <Link href={item.url} prefetch>
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </Link>
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        );
    }

    return (
        <Collapsible asChild open={open} onOpenChange={handleOpenChange} className="group/collapsible">
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton tooltip={label} isActive={containsActive && !open}>
                        {Icon && <Icon />}
                        <span>{label}</span>
                        <ChevronDown className="ms-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-180" />
                    </SidebarMenuButton>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <SidebarMenuSub>
                        {group.items.map((item) => (
                            <SidebarMenuSubItem key={item.title}>
                                <SidebarMenuSubButton asChild isActive={isActivePath(item.url, currentPath)}>
                                    <Link href={item.url} prefetch>
                                        {item.icon && <item.icon />}
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                        ))}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}
