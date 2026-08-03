import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import AppLogoIcon from './app-logo-icon';

/**
 * Sidebar/header brand mark. A branch that uploaded a logo wears it here along
 * with its own name; everyone else (super-admins, branches with no logo) keeps
 * the generic Alnaasik mark.
 */
export default function AppLogo() {
    const branch = usePage<SharedData>().props.auth?.branch;

    return (
        <>
            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center overflow-hidden rounded-md">
                {branch?.logoUrl ? (
                    <img src={branch.logoUrl} alt={branch.name} className="size-full object-contain" />
                ) : (
                    <AppLogoIcon className="size-5 text-white dark:text-black" />
                )}
            </div>
            <div className="grid flex-1 text-right text-sm">
                <span className="truncate font-semibold leading-tight">{branch?.name ?? 'الناسخ'}</span>
                <span className="text-muted-foreground truncate text-[11px] leading-tight">نظام الإدارة المتكامل</span>
            </div>
        </>
    );
}
