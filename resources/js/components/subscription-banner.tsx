import { formatDateNumeric } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { CalendarX } from 'lucide-react';

/** تاسك 228: لكل مستخدمي الفرع بعد نهاية الاشتراك — في المهلة، أو وضع القراءة فقط بعدها. */
export function SubscriptionBanner() {
    const subscription = usePage<SharedData>().props.auth?.branch?.subscription;

    if (subscription?.state !== 'grace' && subscription?.state !== 'expired') {
        return null;
    }

    return (
        <div className="flex items-center justify-center gap-2 bg-red-600 px-4 py-2 text-center text-sm font-medium text-white">
            <CalendarX className="size-4 shrink-0" />
            <span>
                {subscription.state === 'grace'
                    ? `انتهى اشتراك الفرع — مهلة حتى ${formatDateNumeric(subscription.graceUntil!)}، تواصل مع الإدارة للتجديد`
                    : 'انتهى اشتراك الفرع — النظام في وضع القراءة فقط، تواصل مع الإدارة للتجديد'}
            </span>
        </div>
    );
}
