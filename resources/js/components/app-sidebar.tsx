import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ArrowLeftRight, Award, Bell, BookOpen, ClipboardList, FileText, GitBranch, Handshake, LayoutGrid, LucideIcon, Package, Receipt, ServerIcon, ShoppingCart, Trophy, Truck, Undo2, Users, Wallet } from 'lucide-react';
import { useEffect, useRef } from 'react';
import AppLogo from './app-logo';

const SIDEBAR_SCROLL_KEY = 'app-sidebar-scroll';


export function AppSidebar() {
    const { auth } = usePage<{ auth: { sidebarItems?: Array<Omit<NavItem, 'icon'> & { icon: string }> } }>().props;

    const ICON_MAP: Record<string, LucideIcon> = {
        LayoutGrid,
        GitBranch,
        ServerIcon,
        ShoppingCart,
        Package,
        ArrowLeftRight,
        Users,
        FileText,
        Wallet,
        Undo2,
        Receipt,
        Handshake,
        Award,
        Bell,
        Truck,
        ClipboardList,
        Trophy,
        BookOpen,
    };

    const mainNavItems = (auth.sidebarItems ?? []).map((item) => ({
        ...item,
        icon: ICON_MAP[item.icon as string] ?? LayoutGrid,
    }));

    // const mainNavItems: NavItem[] = auth.sidebarItems || [];

    // The layout is non-persistent, so the sidebar remounts on every navigation
    // and its scroll position is lost. Restore it from sessionStorage on mount
    // and keep it in sync as the user scrolls.
    const contentRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const el = contentRef.current;
        if (!el) return;

        const saved = sessionStorage.getItem(SIDEBAR_SCROLL_KEY);
        if (saved) el.scrollTop = Number(saved);

        const handleScroll = () => sessionStorage.setItem(SIDEBAR_SCROLL_KEY, String(el.scrollTop));
        el.addEventListener('scroll', handleScroll, { passive: true });
        return () => el.removeEventListener('scroll', handleScroll);
    }, []);

    return (
        <Sidebar collapsible="icon" variant="inset" side="right">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent ref={contentRef}>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                {/* <NavFooter items={footerNavItems} className="mt-auto" /> */}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

