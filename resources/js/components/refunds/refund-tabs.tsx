import { cn } from '@/lib/utils';
import refunds from '@/routes/refunds';
import { Link } from '@inertiajs/react';

/** تاسك 135 — «المرتجعات» و«طلبات الاسترجاع» تبويبان لشاشةٍ واحدة. */
export default function RefundTabs({ active }: { active: 'refunds' | 'requests' }) {
    const tabs = [
        { key: 'refunds', label: 'المرتجعات', href: refunds.index().url },
        { key: 'requests', label: 'طلبات الاسترجاع', href: refunds.requests.index().url },
    ] as const;

    return (
        <div className="mb-6 flex gap-1 border-b">
            {tabs.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.href}
                    className={cn(
                        '-mb-px border-b-2 px-4 py-2 text-sm font-medium',
                        active === tab.key ? 'border-primary text-foreground' : 'text-muted-foreground hover:text-foreground border-transparent',
                    )}
                >
                    {tab.label}
                </Link>
            ))}
        </div>
    );
}
