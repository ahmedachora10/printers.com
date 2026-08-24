import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ArrowLeftRight, Award, Bell, BookOpen, Boxes, CalendarDays, ChartPie, ClipboardCheck, ClipboardList, FileText, FolderKanban, GitBranch, Handshake, LayoutGrid, LucideIcon, Package, Receipt, ServerIcon, Settings, ShoppingBasket, ShoppingCart, Tags, Ticket, TrendingUp, Trophy, Truck, Undo2, User, Users, Wallet } from 'lucide-react';
import { useEffect, useRef } from 'react';
import AppLogo from './app-logo';

const SIDEBAR_SCROLL_KEY = 'app-sidebar-scroll';


type RawNavItem = Omit<NavItem, 'icon'> & { icon: string };
type RawNavGroup = { label: string | null; icon: string | null; items: RawNavItem[] };

export function AppSidebar() {
    const { auth } = usePage<{ auth: { sidebarItems?: RawNavGroup[] } }>().props;

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
        ClipboardCheck,
        ShoppingBasket,
        Trophy,
        BookOpen,
        TrendingUp,
        ChartPie,
        CalendarDays,
        Boxes,
        Tags,
        // Named by sidebar items that were silently falling back to LayoutGrid.
        Settings,
        User,
        FolderKanban,
        Ticket,
    };

    const navGroups = (auth.sidebarItems ?? []).map((group) => ({
        label: group.label,
        icon: group.icon ? (ICON_MAP[group.icon] ?? LayoutGrid) : undefined,
        items: group.items.map((item) => ({
            ...item,
            icon: ICON_MAP[item.icon] ?? LayoutGrid,
        })),
    }));

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
                <NavMain groups={navGroups} />
            </SidebarContent>

            <SidebarFooter>
                {/* <NavFooter items={footerNavItems} className="mt-auto" /> */}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

