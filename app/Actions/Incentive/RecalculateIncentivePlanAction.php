<?php

namespace App\Actions\Incentive;

use App\Actions\Report\CollectionEvents;
use App\Enums\IncentivePlanStatusEnum;
use App\Models\IncentivePlan;
use App\Models\ServiceInvoice;
use App\Notifications\IncentiveShortfallNotification;
use App\Support\BranchNotifiables;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

class RecalculateIncentivePlanAction
{
    public function __construct(private readonly CollectionEvents $events) {}

    /**
     * Refresh a plan's achieved sales and derive its status. A paid plan is
     * frozen — its payout is already recorded and must not be re-evaluated.
     */
    public function handle(IncentivePlan $plan): IncentivePlan
    {
        if ($plan->status === IncentivePlanStatusEnum::Paid) {
            return $plan;
        }

        // تاسك 203: شهرٌ انتهى لا تُعاد مبيعاته — أثر أي مرتجع لاحق في شهر تسجيله.
        // الخطة الجديدة لشهرٍ مضى تُحتسب مرة عند إنشائها.
        $achieved = $plan->periodEnded() && ! $plan->wasRecentlyCreated
            ? (float) $plan->achieved_amount
            : $this->achievedSales($plan);

        $plan->achieved_amount = $achieved;
        $plan->status = $this->deriveStatus($plan, $achieved);
        $plan->save();

        return $plan;
    }

    /**
     * المحقَّق = مبيعات الموظف في شهر الخطة بأحداث التحصيل (CollectionEvents):
     *
     * - تاسك 201: كل دفعة تُحسب بقدرها في شهر قبضها، لا الفاتورة كاملةً عند
     *   أول دفعة. المسدَّدة عند البيع بإجماليها يوم سدادها.
     * - تاسك 203: المرتجع (جزئياً أو كلياً) حدثٌ سالب في شهر تسجيله، فلا يُمسّ
     *   شهرٌ مقفل، ولا يُطرح إلا ما سبق احتسابه. قد يصير المحقَّق سالباً، ويبقى
     *   في شهره بلا ترحيل.
     * - المساعدون: حصة كل مساعد من كل حدث تُحسب له وتُطرح من صاحب الفاتورة.
     */
    public function achievedSales(IncentivePlan $plan): float
    {
        $start = CarbonImmutable::create($plan->period_year, $plan->period_month, 1)->startOfMonth();

        return round((float) $this->events->perEmployee('service_invoices', [
            'branchIds' => [$plan->branch_id],
            'from' => $start,
            'to' => $start->endOfMonth(),
        ])->where('events.user_id', $plan->user_id)->sum('events.realized'), 2);
    }

    /**
     * يُستدعى بعد كل دفعة أو اعتماد أو مرتجع: يحدّث خطط صاحب الفاتورة ومساعديها
     * لكل شهرٍ فيه حدث تحصيل للفاتورة. الخطة المصروفة لا تُعاد — إن نزل
     * محقَّقها تحت الهدف يُنبَّه مدير الفرع فقط، ولا يُسجَّل حسمٌ تلقائي.
     */
    public function refreshForInvoice(ServiceInvoice $invoice): void
    {
        $periods = $invoice->payments()->pluck('paid_at')
            ->push($invoice->paid_at)
            ->merge($invoice->refunds()->pluck('created_at'))
            ->filter()
            ->map(fn ($at) => CarbonImmutable::parse($at))
            ->unique(fn (CarbonImmutable $at) => $at->format('Y-n'));

        if ($periods->isEmpty()) {
            return;
        }

        $plans = IncentivePlan::query()
            ->whereIn('user_id', $invoice->helpers()->pluck('user_id')->push($invoice->user_id))
            ->where('branch_id', $invoice->branch_id)
            ->where(fn ($q) => $periods->each(fn (CarbonImmutable $at) => $q->orWhere(fn ($p) => $p
                ->where('period_year', $at->year)
                ->where('period_month', $at->month))))
            ->get();

        foreach ($plans as $plan) {
            if ($plan->status !== IncentivePlanStatusEnum::Paid) {
                $this->handle($plan);

                continue;
            }

            if ($this->achievedSales($plan) < (float) $plan->target_amount) {
                Notification::send(
                    BranchNotifiables::forBranch((int) $plan->branch_id, ['branch-admin']),
                    new IncentiveShortfallNotification($plan, $invoice),
                );
            }
        }
    }

    private function deriveStatus(IncentivePlan $plan, float $achieved): IncentivePlanStatusEnum
    {
        // target_amount هو أدنى شريحة (تاسك 105) — بلوغه يكفي لـ«محقَّقة».
        if ($achieved >= (float) $plan->target_amount) {
            return IncentivePlanStatusEnum::Achieved;
        }

        // Target not met. Once the month is over the plan can no longer recover.
        return $plan->periodEnded()
            ? IncentivePlanStatusEnum::Missed
            : IncentivePlanStatusEnum::Active;
    }
}
