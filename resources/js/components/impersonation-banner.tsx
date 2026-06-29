import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { LogOut, UserCog } from 'lucide-react';
import impersonate from '@/routes/impersonate';

export function ImpersonationBanner() {
    const { auth } = usePage<SharedData>().props;
    const impersonating = auth?.impersonating;

    if (!impersonating?.active) {
        return null;
    }

    function leave() {
        router.delete(impersonate.leave().url, { preserveScroll: false });
    }

    return (
        <div className="flex items-center justify-center gap-3 bg-amber-500 px-4 py-2 text-sm font-medium text-amber-950">
            <UserCog className="size-4 shrink-0" />
            <span>
                أنت تتصفّح كـ <span className="font-bold">{impersonating.viewingName}</span>
            </span>
            <Button
                size="sm"
                variant="outline"
                className="h-7 border-amber-700/40 bg-amber-100 text-amber-950 hover:bg-amber-50"
                onClick={leave}
            >
                <LogOut className="size-3.5" />
                العودة لحسابي
            </Button>
        </div>
    );
}
