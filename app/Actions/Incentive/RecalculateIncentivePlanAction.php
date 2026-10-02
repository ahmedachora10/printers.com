<?php

namespace App\Actions\Incentive;

use App\Enums\IncentivePlanStatusEnum;
use App\Enums\InvoiceStatusEnum;
use App\Models\IncentivePlan;
use App\Models\ServiceInvoice;
use App\Notifications\IncentiveShortfallNotification;
use App\Support\BranchNotifiables;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class RecalculateIncentivePlanAction
{
    /**
     * Refresh a plan's achieved sales and derive its status. A paid plan is
     * frozen — its payout is already recorded and must not be re-evaluated.
     */
    public function handle(IncentivePlan $plan): IncentivePlan
    {
        if ($plan->status === IncentivePlanStatusEnum::Paid) {
            return $plan;
        }

        $achieved = $this->achievedSales($plan);

        $plan->achieved_amount = $achieved;
        $plan->status = $this->deriveStatus($plan, $achieved);
        $plan->save();

        return $plan;
    }

    /**
     * تاسك 160 — المحقَّق = مبيعات الموظف **المعتمدة** صافيةً من المرتجعات.
     *
     * - المعتمدة: مدفوعة أو مدفوعة جزئياً (InvoiceStatusEnum::approved)؛ الآجلة لا
     *   تُحتسب حتى يعتمدها المحاسب، والمرتجعة كلياً خارجة بحالتها.
     * - الشهر: شهر الاعتماد = أول دفعة قُبضت وإلا paid_at — نفس اشتقاق التقرير
     *   اليومي، فلا تقفز فاتورة العربون إلى شهر آخر حين يكتمل سدادها.
     * - المرتجع الجزئي يُطرح في شهر اعتماد الفاتورة لا شهر المرتجع.
     */
    public function achievedSales(IncentivePlan $plan): float
    {
        $start = CarbonImmutable::create($plan->period_year, $plan->period_month, 1)->startOfMonth();
        $end = $start->endOfMonth();

        $firstPayment = DB::table('invoice_payments')
            ->where('invoice_type', ServiceInvoice::class)
            ->groupBy('invoice_id')
            ->select('invoice_id', DB::raw('MIN(paid_at) as first_paid_at'));

        $refunds = DB::table('refunds')
            ->where('invoice_type', ServiceInvoice::class)
            ->whereNull('deleted_at')
            ->groupBy('invoice_id')
            ->select('invoice_id', DB::raw('SUM(amount) as refunded'));

        return (float) ServiceInvoice::query()
            ->leftJoinSub($firstPayment, 'fp', 'fp.invoice_id', '=', 'service_invoices.id')
            ->leftJoinSub($refunds, 'r', 'r.invoice_id', '=', 'service_invoices.id')
            ->where('service_invoices.user_id', $plan->user_id)
            ->where('service_invoices.branch_id', $plan->branch_id)
            ->whereIn('service_invoices.status', InvoiceStatusEnum::approved())
            ->whereBetween(DB::raw('COALESCE(fp.first_paid_at, service_invoices.paid_at)'), [$start, $end])
            ->sum(DB::raw('service_invoices.total_amount - COALESCE(r.refunded, 0)'));
    }

    /**
     * يُستدعى بعد اعتماد الفاتورة أو استرجاعها: يحدّث خطة صاحبها لشهر اعتمادها.
     * الخطة المصروفة لا تُعاد — إن نزل محقَّقها تحت الهدف يُنبَّه مدير الفرع فقط،
     * ولا يُسجَّل حسمٌ تلقائي على الموظف.
     */
    public function refreshForInvoice(ServiceInvoice $invoice): void
    {
        $approvedAt = $invoice->payments()->min('paid_at') ?? $invoice->paid_at;

        if ($approvedAt === null) {
            return;
        }

        $approvedAt = CarbonImmutable::parse($approvedAt);

        $plan = IncentivePlan::query()
            ->where('user_id', $invoice->user_id)
            ->where('branch_id', $invoice->branch_id)
            ->where('period_year', $approvedAt->year)
            ->where('period_month', $approvedAt->month)
            ->first();

        if ($plan === null) {
            return;
        }

        if ($plan->status !== IncentivePlanStatusEnum::Paid) {
            $this->handle($plan);

            return;
        }

        if ($this->achievedSales($plan) < (float) $plan->target_amount) {
            Notification::send(
                BranchNotifiables::forBranch((int) $plan->branch_id, ['branch-admin']),
                new IncentiveShortfallNotification($plan, $invoice),
            );
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
