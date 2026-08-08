import { useEffect, useState } from 'react';

/**
 * Tailwind's `lg`. The pinned sidebar eats 16rem, which an iPad in portrait
 * (768px) cannot spare — below this width the navigation becomes a Sheet
 * instead, so the content keeps the full viewport. Keep this in step with the
 * `lg:` breakpoints in `components/ui/sidebar.tsx`.
 */
const MOBILE_BREAKPOINT = 1024;

export function useIsMobile() {
    const [isMobile, setIsMobile] = useState<boolean | undefined>(undefined);

    useEffect(() => {
        const mql = window.matchMedia(`(max-width: ${MOBILE_BREAKPOINT - 1}px)`);

        const onChange = () => {
            setIsMobile(window.innerWidth < MOBILE_BREAKPOINT);
        };

        mql.addEventListener('change', onChange);
        setIsMobile(window.innerWidth < MOBILE_BREAKPOINT);

        return () => mql.removeEventListener('change', onChange);
    }, []);

    return !!isMobile;
}
