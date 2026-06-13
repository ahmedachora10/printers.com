import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ArrowLeftRight, FileText, GitBranch, LayoutGrid, LucideIcon, Package, Receipt, ServerIcon, ShoppingCart, Undo2, Users, Wallet } from 'lucide-react';
import AppLogo from './app-logo';


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
    };

    const mainNavItems = (auth.sidebarItems ?? []).map((item) => ({
        ...item,
        icon: ICON_MAP[item.icon as string] ?? LayoutGrid,
    }));

    // const mainNavItems: NavItem[] = auth.sidebarItems || [];

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

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                {/* <NavFooter items={footerNavItems} className="mt-auto" /> */}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

