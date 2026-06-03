import AppLogoIcon from './app-logo-icon';

export default function AppLogo() {
    return (
        <>
            <div className="bg-sidebar-primary text-sidebar-primary-foreground flex aspect-square size-8 items-center justify-center rounded-md">
                <AppLogoIcon className="size-5 text-white dark:text-black" />
            </div>
            <div className="grid flex-1 text-right text-sm">
                <span className="truncate font-semibold leading-tight">الناسخ</span>
                <span className="text-muted-foreground truncate text-[11px] leading-tight">نظام الإدارة المتكامل</span>
            </div>
        </>
    );
}
