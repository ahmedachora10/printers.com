<?php

namespace App\Http\Controllers;

use App\Http\Resources\Deduction\EmployeeDeductionResource;
use App\Http\Resources\Incentive\IncentivePlanResource;
use App\Models\EmployeeDeduction;
use App\Models\IncentivePlan;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «حوافزي وحسوماتي» — وجه الموظف من شاشة الحوافز.
 *
 * الشاشة الإدارية `/incentives` مغلقةٌ على الإدارة، فكان الموظف يُحسم عليه ولا
 * يجد أين يقرأ الحسم؛ الإشعار وحده يمرّ ثم يُدفن. هنا يقرأ الطرفين: ما استُهدف
 * منه وما صُرف له، وما حُسم عليه بسببه ومن طبّقه.
 *
 * قراءةٌ خالصة ومقصورةٌ على صاحبها: الاستعلامان مقيَّدان بـ`user_id` الفاعل، فلا
 * معرِّفَ يأتي من الطلب ولا سبيلَ لقراءة صفّ غيره.
 */
class MyIncentiveController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $request->user()->id;

        $plans = IncentivePlan::query()
            ->where('user_id', $userId)
            ->with(['bonusPayments' => fn ($q) => $q->with('paidBy:id,name')->latest('paid_at')])
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->paginate(10)
            ->withQueryString();

        $deductions = EmployeeDeduction::query()
            ->where('user_id', $userId)
            ->with(['deductedBy:id,name'])
            ->latest('deducted_at')
            ->paginate(10, pageName: 'deductionsPage')
            ->withQueryString();

        return Inertia::render('incentives/my', [
            'plans' => IncentivePlanResource::collection($plans),
            'deductions' => EmployeeDeductionResource::collection($deductions),
            'currentPlan' => $this->currentPlan($userId),
            'totals' => $this->totals($userId),
        ]);
    }

    /** خطة الشهر الجاري إن وُجدت — هي ما يعني الموظف اليوم. */
    private function currentPlan(int $userId): ?IncentivePlanResource
    {
        $plan = IncentivePlan::query()
            ->where('user_id', $userId)
            ->where('period_month', Carbon::now()->month)
            ->where('period_year', Carbon::now()->year)
            ->with(['bonusPayments' => fn ($q) => $q->with('paidBy:id,name')->latest('paid_at')])
            ->first();

        return $plan ? new IncentivePlanResource($plan) : null;
    }

    /**
     * الأرقام الكبيرة: كل ما صُرف وكل ما حُسم منذ البداية، وحسومات الشهر الجاري
     * وحدها — وهي ما يظهر في كارت لوحة التحكم.
     *
     * @return array<string, float|int>
     */
    private function totals(int $userId): array
    {
        $bonusPaid = (float) IncentivePlan::query()
            ->where('user_id', $userId)
            ->withSum('bonusPayments', 'amount')
            ->get()
            ->sum('bonus_payments_sum_amount');

        $deductions = (float) EmployeeDeduction::query()->where('user_id', $userId)->sum('amount');

        $monthDeductions = (float) EmployeeDeduction::query()
            ->where('user_id', $userId)
            ->deductedBetween(Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth())
            ->sum('amount');

        return [
            'bonusPaid' => round($bonusPaid, 2),
            'deductions' => round($deductions, 2),
            'monthDeductions' => round($monthDeductions, 2),
            'deductionCount' => EmployeeDeduction::query()->where('user_id', $userId)->count(),
            'net' => round($bonusPaid - $deductions, 2),
        ];
    }
}
