import { Badge } from '@/components/ui/badge';
import { formatDateTimeNumeric } from '@/lib/utils';
import { type DeliveryStatus } from '@/types/invoice';
import { CalendarClock } from 'lucide-react';

/**
 * موعد تسليم العمل، ملوَّناً بحالته: الأحمر لما تأخّر عن موعده، والكهرماني لما
 * يُسلَّم اليوم، والمحايد لما هو قادم. الحالة تأتي محسوبة من الخادم
 * (DeliveryStatusEnum) فلا يُعاد اشتقاقها هنا من التاريخ.
 */
const STATUS_STYLES: Record<DeliveryStatus, string> = {
    overdue: 'border-red-300 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300',
    today: 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300',
    upcoming: 'border-border bg-muted/60 text-muted-foreground',
};

const STATUS_LABELS: Record<DeliveryStatus, string> = {
    overdue: 'متأخر عن موعده',
    today: 'تسليم اليوم',
    upcoming: '',
};

interface Props {
    deliveryAt: string | null;
    deliveryStatus: DeliveryStatus | null;
    /** يُظهر وصف الحالة (متأخر / اليوم) تحت الموعد — للقوائم. */
    showLabel?: boolean;
}

export default function DeliveryBadge({ deliveryAt, deliveryStatus, showLabel = false }: Props) {
    if (!deliveryAt) return null;

    const status: DeliveryStatus = deliveryStatus ?? 'upcoming';
    const label = STATUS_LABELS[status];

    return (
        <span className="inline-flex flex-col items-start gap-1">
            <Badge variant="outline" className={`gap-1 tabular-nums ${STATUS_STYLES[status]}`}>
                <CalendarClock className="size-3.5 shrink-0" aria-hidden />
                <span dir="ltr">{formatDateTimeNumeric(deliveryAt)}</span>
            </Badge>
            {showLabel && label && <span className="text-xs font-medium opacity-80">{label}</span>}
        </span>
    );
}
