<?php

namespace App\Http\Controllers;

use App\Actions\Incentive\CreateIncentivePlanAction;
use App\Actions\Incentive\DeleteIncentivePlanAction;
use App\Actions\Incentive\PayBonusAction;
use App\Actions\Incentive\RecalculateIncentivePlanAction;
use App\Actions\Incentive\UpdateIncentivePlanAction;
use App\Enums\DeductionReasonEnum;
use App\Enums\IncentiveBonusTypeEnum;
use App\Enums\IncentivePlanStatusEnum;
use App\Enums\Roles;
use App\Http\Requests\Incentive\PayBonusRequest;
use App\Http\Requests\Incentive\StoreIncentivePlanRequest;
use App\Http\Requests\Incentive\UpdateIncentivePlanRequest;
use App\Http\Resources\Deduction\EmployeeDeductionResource;
use App\Http\Resources\Incentive\IncentivePlanResource;
use App\Models\Branch;
use App\Models\EmployeeDeduction;
use App\Models\IncentivePlan;
use App\Models\User;
use App\Notifications\BonusPaidNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class IncentiveController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', IncentivePlan::class);

        $isSuper = $request->user()->roleName->isSuperAdmin();
        $branchId = $isSuper
            ? ($request->filled('branch_id') ? (int) $request->input('branch_id') : null)
            : $request->user()->branchId;

        $plans = IncentivePlan::query()
            ->with(['user:id,name', 'branch:id,name', 'bonusPayments' => fn ($q) => $q->with('paidBy:id,name')->latest('paid_at')])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('period_year'), fn ($q) => $q->where('period_year', (int) $request->input('period_year')))
            ->when($request->filled('period_month'), fn ($q) => $q->where('period_month', (int) $request->input('period_month')))
            ->when($request->filled('search'), fn ($q) => $q->whereHas(
                'user',
                fn (Builder $u) => $u->where('name', 'like', '%'.$request->input('search').'%')
            ))
            ->orderByDesc('period_year')
            ->orderByDesc('period_month')
            ->paginate(15)
            ->withQueryString();

        // تاسك 74: الحسومات بندٌ مستقلّ يُعرض بجانب الخطط ولا يُعيد كتابة أي رقم
        // منشور — لا عمولة ولا مكافأة. صفحتها الخاصّة كي لا يزاحم تصفيحُها تصفيحَ
        // الخطط في الشاشة الواحدة.
        $deductions = EmployeeDeduction::query()
            ->with(['user:id,name', 'branch:id,name', 'deductedBy:id,name'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($request->filled('search'), fn ($q) => $q->whereHas(
                'user',
                fn (Builder $u) => $u->where('name', 'like', '%'.$request->input('search').'%')
            ))
            ->latest('deducted_at')
            ->paginate(10, pageName: 'deductionsPage')
            ->withQueryString();

        return Inertia::render('incentives/index', [
            'plans' => IncentivePlanResource::collection($plans),
            'deductions' => EmployeeDeductionResource::collection($deductions),
            'deductionsTotal' => (float) EmployeeDeduction::query()
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->sum('amount'),
            'deductionReasons' => array_map(
                fn (DeductionReasonEnum $c) => ['value' => $c->value, 'label' => $c->label(), 'requiresNote' => $c->requiresNote()],
                DeductionReasonEnum::cases(),
            ),
            'employees' => $this->employees($branchId, $isSuper),
            'bonusTypes' => array_map(fn ($c) => ['value' => $c->value, 'label' => $c->label()], IncentiveBonusTypeEnum::cases()),
            'statuses' => array_map(fn ($c) => ['value' => $c->value, 'label' => $c->label()], IncentivePlanStatusEnum::cases()),
            'branches' => $isSuper
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : null,
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'period_month' => $request->input('period_month'),
                'period_year' => $request->input('period_year'),
                'branch_id' => $request->input('branch_id'),
            ],
        ]);
    }

    public function store(StoreIncentivePlanRequest $request, CreateIncentivePlanAction $action): RedirectResponse
    {
        Gate::authorize('create', IncentivePlan::class);

        $data = $request->validated();
        $data['branch_id'] = $this->resolveBranchId((int) $data['user_id']);

        $action->handle($data);

        return back(fallback: route('incentives.index'))->with('success', 'تم إنشاء خطة الحوافز بنجاح');
    }

    public function update(UpdateIncentivePlanRequest $request, IncentivePlan $incentivePlan, UpdateIncentivePlanAction $action): RedirectResponse
    {
        Gate::authorize('update', $incentivePlan);

        $data = $request->validated();
        $data['branch_id'] = $this->resolveBranchId((int) $data['user_id']);

        $action->handle($incentivePlan, $data);

        return back(fallback: route('incentives.index'))->with('success', 'تم تحديث خطة الحوافز بنجاح');
    }

    public function destroy(IncentivePlan $incentivePlan, DeleteIncentivePlanAction $action): RedirectResponse
    {
        Gate::authorize('delete', $incentivePlan);

        $action->handle($incentivePlan);

        return back(fallback: route('incentives.index'))->with('success', 'تم حذف خطة الحوافز بنجاح');
    }

    /**
     * Refresh achieved sales & status for every non-paid plan in scope.
     */
    public function recalculate(Request $request, RecalculateIncentivePlanAction $action): RedirectResponse
    {
        Gate::authorize('viewAny', IncentivePlan::class);

        $isSuper = $request->user()->roleName->isSuperAdmin();
        $branchId = $isSuper ? null : $request->user()->branchId;

        IncentivePlan::query()
            ->where('status', '!=', IncentivePlanStatusEnum::Paid->value)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->each(fn (IncentivePlan $plan) => $action->handle($plan));

        return back(fallback: route('incentives.index'))->with('success', 'تم تحديث المبيعات المحققة');
    }

    public function pay(PayBonusRequest $request, IncentivePlan $incentivePlan, PayBonusAction $action): RedirectResponse
    {
        Gate::authorize('pay', $incentivePlan);

        $payment = $action->handle($incentivePlan, $request->validated());

        $incentivePlan->user?->notify(new BonusPaidNotification($payment));

        return back(fallback: route('incentives.index'))->with('success', 'تم صرف المكافأة بنجاح');
    }

    /**
     * Eligible employees for new plans, scoped to the active branch. Plans are
     * always filed under the chosen employee's own branch.
     */
    private function resolveBranchId(int $userId): int
    {
        $user = User::query()->findOrFail($userId);
        $branchId = $user->getAttributes()['branch_id'] ?? null;

        abort_if($branchId === null, 422, 'الموظف غير مرتبط بفرع.');

        // Branch-admins may only file plans for their own branch's employees.
        if (! auth()->user()->roleName->isSuperAdmin()) {
            abort_unless((int) $branchId === auth()->user()->branchId, 403);
        }

        return (int) $branchId;
    }

    /**
     * @return Collection<int, array{id: int, name: string, branchName: string|null}>
     */
    private function employees(?int $branchId, bool $isSuper)
    {
        return User::query()
            ->whereHas('roles', fn (Builder $q) => $q->where('name', Roles::EMPLOYEE->value))
            ->where('is_active', true)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->with('branch:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'branch_id'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'branchName' => $isSuper ? $u->branch?->name : null,
            ])
            ->values();
    }
}
