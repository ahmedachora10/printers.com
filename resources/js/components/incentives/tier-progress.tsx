import { formatCurrency } from '@/lib/utils';
import { type IncentivePlan } from '@/types/incentive';

/**
 * شريط إنجاز خطة الحوافز. ذات الشريحة الواحدة تُقاس على الهدف كما كانت؛ وذات
 * الشرائح (تاسك 105) تُقاس على أعلى عتبة، بعلامةٍ عند كل عتبة وسطرٍ يقول أين
 * يقف الموظف وكم بقي على التالية.
 */
export default function TierProgress({ plan }: { plan: Pick<IncentivePlan, 'tiers' | 'achievedAmount' | 'progressPct' | 'isTargetMet' | 'reachedTier' | 'nextTier'> }) {
    const multi = plan.tiers.length > 1;
    const top = plan.tiers[plan.tiers.length - 1].threshold;
    const pct = multi ? Math.min(100, (plan.achievedAmount / top) * 100) : Math.min(100, plan.progressPct);

    return (
        <div className="space-y-1">
            <div className="bg-muted relative h-1.5 w-full overflow-hidden rounded-full">
                <div className={`h-full rounded-full ${plan.isTargetMet ? 'bg-emerald-500' : 'bg-primary'}`} style={{ width: `${pct}%` }} />
                {multi &&
                    plan.tiers.slice(0, -1).map((tier) => (
                        <span
                            key={tier.threshold}
                            className="bg-background absolute top-0 h-full w-0.5"
                            style={{ insetInlineStart: `${(tier.threshold / top) * 100}%` }}
                        />
                    ))}
            </div>
            {multi && (
                <p className="text-muted-foreground text-xs">
                    {plan.reachedTier ? `الشريحة ${plan.reachedTier} من ${plan.tiers.length}` : 'لم تُبلغ أي شريحة'}
                    {plan.nextTier
                        ? ` — باقٍ ${formatCurrency(plan.nextTier.remaining)} على الشريحة ${plan.nextTier.number}`
                        : ' — بلغ أعلى شريحة'}
                </p>
            )}
        </div>
    );
}
