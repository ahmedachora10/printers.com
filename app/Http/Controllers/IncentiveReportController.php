<?php

namespace App\Http\Controllers;

use App\Actions\Incentive\ResolveIncentiveScope;
use App\Enums\DeductionReasonEnum;
use App\Enums\IncentivePlanStatusEnum;
use App\Enums\Roles;
use App\Exports\IncentiveReportExport;
use App\Http\Requests\Report\IncentiveReportFilterRequest;
use App\Models\Branch;
use App\Models\EmployeeDeduction;
use App\Models\IncentivePlan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * تقرير الحوافز والخصومات: ما استحقّه الموظف وما صُرف له، وما حُسم عليه، والصافي.
 *
 * البندان مقروءان معاً لأن الإدارة تقرؤهما معاً في كشف الراتب، ومفصولان في
 * التخزين لأن الحسم لا يمسّ مكافأةً مصروفة ولا صفّاً في `commission_ledger` —
 * كلاهما جدولٌ غير قابل للتعديل. فالصافي هنا **قراءةٌ** لا قيدٌ جديد.
 *
 * والمكافأة تُحسب من `IncentivePlan::bonusAmount()` لا بحسابٍ موازٍ في SQL: قاعدة
 * «النسبة من المستهدف لا من المحقق» (تاسك 73) لها موضعٌ واحد، ونسخُها هنا كان
 * سيجعل التقرير يخالف ما يُصرف فعلاً متى تغيّرت. وحجم البيانات شهريٌّ صغير،
 * فالتجميع في PHP لا يكلّف شيئاً.
 *
 * المدى الافتراضي هو **الشهر الجاري** لا اليوم كبقية التقارير: الخطة شهرٌ بطبعها،
 * ويومٌ واحد لا يعني فيها شيئاً.
 */
class IncentiveReportController extends Controller
{
    public function index(IncentiveReportFilterRequest $request, ResolveIncentiveScope $resolveScope): Response
    {
        Gate::authorize('viewAny', IncentivePlan::class);

        $scope = $this->scope($request, $resolveScope);
        $plans = $this->plans($scope);
        $deductions = $this->deductions($scope);
        $summary = $this->summary($plans, $deductions, $scope['isSuper']);

        return Inertia::render('reports/incentives/index', [
            'summary' => $summary,
            'totals' => $this->totals($summary),
            'byReason' => $this->byReason($deductions),
            'plans' => $plans->map(fn(IncentivePlan $plan) => $this->planRow($plan))->values(),
            'deductions' => $deductions->map(fn(EmployeeDeduction $d) => $this->deductionRow($d))->values(),
            'filters' => [
                'from' => $scope['from']->toDateString(),
                'to' => $scope['to']->toDateString(),
                'branch' => $scope['isSuper'] && $scope['branchId'] ? (string) $scope['branchId'] : null,
                'employee' => $scope['userId'] ? (string) $scope['userId'] : null,
                'status' => $scope['status'],
            ],
            'defaultFrom' => $this->defaultFrom()->toDateString(),
            'defaultTo' => $this->defaultTo()->toDateString(),
            'statuses' => array_map(
                fn(IncentivePlanStatusEnum $c) => ['value' => $c->value, 'label' => $c->label()],
                IncentivePlanStatusEnum::cases(),
            ),
            'employees' => $this->employeeOptions($scope),
            'branches' => $scope['isSuper']
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'isSuperAdmin' => $scope['isSuper'],
        ]);
    }

    public function export(IncentiveReportFilterRequest $request, ResolveIncentiveScope $resolveScope): BinaryFileResponse|HttpResponse
    {
        Gate::authorize('viewAny', IncentivePlan::class);

        $scope = $this->scope($request, $resolveScope);
        $plans = $this->plans($scope);
        $deductions = $this->deductions($scope);

        return Excel::download(
            new IncentiveReportExport(
                $this->summary($plans, $deductions, $scope['isSuper']),
                $plans->map(fn(IncentivePlan $plan) => $this->planRow($plan))->values(),
                $deductions->map(fn(EmployeeDeduction $d) => $this->deductionRow($d))->values(),
                $scope['isSuper'],
            ),
            'incentives-report-' . now()->format('Y-m-d') . '.xlsx',
        );
    }

    /**
     * @return array{isSuper: bool, branchId: ?int, userId: ?int, from: Carbon, to: Carbon, status: ?string}
     */
    private function scope(IncentiveReportFilterRequest $request, ResolveIncentiveScope $resolveScope): array
    {
        /** @var array{isSuper: bool, branchId: ?int, userId: ?int, from: Carbon, to: Carbon, status: ?string} */
        return $resolveScope->handle($request, $this->defaultFrom(), $this->defaultTo());
    }

    private function defaultFrom(): Carbon
    {
        return Carbon::today()->startOfMonth();
    }

    private function defaultTo(): Carbon
    {
        return Carbon::today()->endOfMonth();
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return Collection<int, IncentivePlan>
     */
    private function plans(array $scope): Collection
    {
        return IncentivePlan::query()
            ->with(['user:id,name', 'branch:id,name', 'bonusPayments'])
            ->when($scope['branchId'], fn(Builder $q) => $q->where('branch_id', $scope['branchId']))
            ->when($scope['userId'], fn(Builder $q) => $q->where('user_id', $scope['userId']))
            ->when($scope['status'], fn(Builder $q) => $q->where('status', $scope['status']))
            ->inPeriodRange($scope['from'], $scope['to'])
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->get();
    }

    /**
     * الخصومات لا حالةَ لها، فمرشِّح حالة الخطة لا يُطبَّق عليها — تطبيقُه كان
     * سيُفرغ الجدول من كل حسمٍ لموظفٍ بلا خطةٍ بتلك الحالة.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, EmployeeDeduction>
     */
    private function deductions(array $scope): Collection
    {
        return EmployeeDeduction::query()
            ->with(['user:id,name', 'branch:id,name', 'deductedBy:id,name'])
            ->when($scope['branchId'], fn(Builder $q) => $q->where('branch_id', $scope['branchId']))
            ->when($scope['userId'], fn(Builder $q) => $q->where('user_id', $scope['userId']))
            ->deductedBetween($scope['from'], $scope['to'])
            ->orderByDesc('deducted_at')
            ->get();
    }

    /**
     * صفٌّ لكل موظفٍ له خطةٌ أو حسمٌ داخل المدى — الاتحاد لا التقاطع، فموظفٌ
     * حُسم عليه بلا خطةٍ يبقى ظاهراً، وهو أهمّ ما في التقرير.
     *
     * @param  Collection<int, IncentivePlan>  $plans
     * @param  Collection<int, EmployeeDeduction>  $deductions
     * @return array<int, array<string, mixed>>
     */
    private function summary(Collection $plans, Collection $deductions, bool $isSuper): array
    {
        $plansByUser = $plans->groupBy('user_id');
        $deductionsByUser = $deductions->groupBy('user_id');

        return $plansByUser->keys()
            ->merge($deductionsByUser->keys())
            ->unique()
            ->map(function (int $userId) use ($plansByUser, $deductionsByUser, $isSuper) {
                /** @var Collection<int, IncentivePlan> $userPlans */
                $userPlans = $plansByUser->get($userId, collect());
                /** @var Collection<int, EmployeeDeduction> $userDeductions */
                $userDeductions = $deductionsByUser->get($userId, collect());

                $target = (float) $userPlans->sum(fn(IncentivePlan $p) => (float) $p->target_amount);
                $achieved = (float) $userPlans->sum(fn(IncentivePlan $p) => (float) $p->achieved_amount);
                // المستحق: مكافآت الخطط التي بلغت هدفها. والمصروف: ما كُتب في
                // bonus_payments فعلاً — والفرق بينهما هو ما لم يُصرف بعد.
                $earned = (float) $userPlans->filter(fn(IncentivePlan $p) => $p->isTargetMet())->sum(fn(IncentivePlan $p) => $p->bonusAmount());
                $paid = (float) $userPlans->sum(fn(IncentivePlan $p) => (float) $p->bonusPayments->sum('amount'));
                $deducted = (float) $userDeductions->sum(fn(EmployeeDeduction $d) => (float) $d->amount);

                $first = $userPlans->first() ?? $userDeductions->first();

                return [
                    'userId' => $userId,
                    'userName' => $first?->user?->name,
                    'branchName' => $isSuper ? $first?->branch?->name : null,
                    'planCount' => $userPlans->count(),
                    'target' => round($target, 2),
                    'achieved' => round($achieved, 2),
                    'progressPct' => $target > 0 ? round($achieved / $target * 100, 1) : 0.0,
                    'bonusEarned' => round($earned, 2),
                    'bonusPaid' => round($paid, 2),
                    'deductions' => round($deducted, 2),
                    'deductionCount' => $userDeductions->count(),
                    // الصافي على المصروف لا على المستحق: الحسم يقابل نقداً خرج،
                    // ومكافأةٌ لم تُصرف بعدُ لا تُطرح منها.
                    'net' => round($paid - $deducted, 2),
                ];
            })
            ->sortByDesc('net')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $summary
     * @return array<string, float|int>
     */
    private function totals(array $summary): array
    {
        $sum = fn(string $key) => round(array_sum(array_column($summary, $key)), 2);
        $target = $sum('target');
        $achieved = $sum('achieved');

        return [
            'employeeCount' => count($summary),
            'planCount' => (int) array_sum(array_column($summary, 'planCount')),
            'target' => $target,
            'achieved' => $achieved,
            'progressPct' => $target > 0 ? round($achieved / $target * 100, 1) : 0.0,
            'bonusEarned' => $sum('bonusEarned'),
            'bonusPaid' => $sum('bonusPaid'),
            'deductions' => $sum('deductions'),
            'deductionCount' => (int) array_sum(array_column($summary, 'deductionCount')),
            'net' => $sum('net'),
        ];
    }

    /**
     * الخصومات حسب سببها — أسبابٌ مغلقة، فالتصنيف يقيس ما تتكرّر لأجله.
     *
     * @param  Collection<int, EmployeeDeduction>  $deductions
     * @return array<int, array<string, mixed>>
     */
    private function byReason(Collection $deductions): array
    {
        return collect(DeductionReasonEnum::cases())
            ->map(function (DeductionReasonEnum $reason) use ($deductions) {
                $rows = $deductions->filter(fn(EmployeeDeduction $d) => $d->reason === $reason);

                return [
                    'reason' => $reason->value,
                    'reasonLabel' => $reason->label(),
                    'count' => $rows->count(),
                    'amount' => round((float) $rows->sum(fn(EmployeeDeduction $d) => (float) $d->amount), 2),
                ];
            })
            ->filter(fn(array $row) => $row['count'] > 0)
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function planRow(IncentivePlan $plan): array
    {
        $target = (float) $plan->target_amount;
        $achieved = (float) $plan->achieved_amount;

        return [
            'id' => $plan->id,
            'userId' => $plan->user_id,
            'userName' => $plan->user?->name,
            'branchName' => $plan->branch?->name,
            'periodLabel' => sprintf('%02d/%d', $plan->period_month, $plan->period_year),
            'target' => round($target, 2),
            'achieved' => round($achieved, 2),
            'progressPct' => $target > 0 ? round($achieved / $target * 100, 1) : 0.0,
            'bonusAmount' => $plan->bonusAmount(),
            'bonusPaid' => round((float) $plan->bonusPayments->sum('amount'), 2),
            'status' => $plan->status->value,
            'statusLabel' => $plan->status->label(),
        ];
    }

    /** @return array<string, mixed> */
    private function deductionRow(EmployeeDeduction $deduction): array
    {
        return [
            'id' => $deduction->id,
            'userId' => $deduction->user_id,
            'userName' => $deduction->user?->name,
            'branchName' => $deduction->branch?->name,
            'amount' => round((float) $deduction->amount, 2),
            'reasonLabel' => $deduction->reason->label(),
            'reasonText' => $deduction->reasonLabel(),
            'deductedBy' => $deduction->deductedBy?->name,
            'deductedAt' => $deduction->deducted_at?->toDateString(),
            'notes' => $deduction->notes,
        ];
    }

    /**
     * الموظفون المرشَّحون للتصفية: موظفو الفرع النشطون — نفس قائمة شاشة الحوافز.
     *
     * @param  array<string, mixed>  $scope
     * @return Collection<int, array{id: int, name: string}>
     */
    private function employeeOptions(array $scope): Collection
    {
        return User::query()
            ->whereHas('roles', fn(Builder $q) => $q->where('name', Roles::EMPLOYEE->value))
            ->when($scope['branchId'], fn(Builder $q) => $q->where('branch_id', $scope['branchId']))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn(User $u) => ['id' => $u->id, 'name' => $u->name])
            ->values();
    }
}
