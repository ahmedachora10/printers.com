import { Badge } from '@/components/ui/badge';
import { formatDateTimeNumeric } from '@/lib/utils';
import { type DeliveryStatus } from '@/types/invoice';
import { CalendarClock, PackageCheck } from 'lucide-react';

/**
 * موعد تسليم العمل، ملوَّناً بحالته: الأخضر لما سُلّم فعلاً، والأحمر لما تأخّر عن
 * موعده، والكهرماني لما يُسلَّم اليوم، والمحايد لما هو قادم. الحالة تأتي محسوبة
 * من الخادم (DeliveryStatusEnum) فلا يُعاد اشتقاقها هنا من التاريخ.
 */
const STATUS_STYLES: Record<DeliveryStatus, string> = {
    delivered: 'border-green-300 bg-green-50 text-green-700 dark:border-green-900 dark:bg-green-950/40 dark:text-green-300',
    overdue: 'border-red-300 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300',
    today: 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300',
    upcoming: 'border-border bg-muted/60 text-muted-foreground',
};

const STATUS_LABELS: Record<DeliveryStatus, string> = {
    delivered: 'تم تسليم العمل',
    overdue: 'متأخر عن موعده',
    today: 'تسليم اليوم',
    upcoming: '',
};

interface Props {
    deliveryAt: string | null;
    deliveryStatus: DeliveryStatus | null;
    /** ختم التسليم الفعلي — يحلّ محلّ الموعد على البادج متى وُجد. */
    deliveredAt?: string | null;
    /** يُظهر وصف الحالة (سُلّم / متأخر / اليوم) تحت الموعد — للقوائم. */
    showLabel?: boolean;
}

export default function DeliveryBadge({ deliveryAt, deliveryStatus, deliveredAt = null, showLabel = false }: Props) {
    const isDelivered = deliveryStatus === 'delivered';

    // فاتورة سُلّم عملها بلا موعد مجدوَل تستحق البادج كذلك — وهي الحالة الوحيدة
    // التي تظهر فيها البادج بلا deliveryAt.
    const shownDate = isDelivered ? (deliveredAt ?? deliveryAt) : deliveryAt;
    if (!shownDate) return null;

    const status: DeliveryStatus = deliveryStatus ?? 'upcoming';
    const label = STATUS_LABELS[status];
    const Icon = isDelivered ? PackageCheck : CalendarClock;

    return (
        <span className="inline-flex flex-col items-start gap-1">
            <Badge variant="outline" className={`gap-1 tabular-nums ${STATUS_STYLES[status]}`}>
                <Icon className="size-3.5 shrink-0" aria-hidden />
                <span dir="ltr">{formatDateTimeNumeric(shownDate)}</span>
            </Badge>
            {showLabel && label && <span className="text-xs font-medium opacity-80">{label}</span>}
        </span>
    );
}
