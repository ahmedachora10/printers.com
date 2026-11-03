<?php

namespace App\Http\Controllers;

use App\Actions\Commission\PayCommissionAction;
use App\Actions\Report\ResolveReportScope;
use App\Http\Requests\Commission\PayCommissionRequest;
use App\Http\Resources\Commission\CommissionPaymentResource;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\CommissionPayment;
use App\Models\EmployeeDeduction;
use App\Models\IncentivePlan;
use App\Models\User;
use App\Notifications\CommissionPaidNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CommissionController extends Controller
{
    public function index(Request $request, ResolveReportScope $scope): Response
    {
        Gate::authorize('viewAny', CommissionPayment::class);

        // تاسك 215: السوبر أدمن يختار فرعاً أو أكثر (?branch=3,7، فارغة = الكل)؛ غيره مثبَّتٌ على فرعه.
        ['isSuper' => $isSuper, 'branchIds' => $branchIds] = $scope->handle($request);
        $inBranches = fn ($q) => $q->when($branchIds, fn ($q) => $q->whereIn('branch_id', $branchIds));

        // Salary is monthly, so the ledger is summed over one month (task 134).
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->input('month'))
            ? $request->input('month')
            : now()->format('Y-m');
        $monthStart = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $ledger = CommissionLedger::query()
            ->tap($inBranches)
            ->whereBetween('earned_at', [$monthStart, $monthEnd])
            ->groupBy('user_id')
            ->toBase()
            ->get([
                'user_id',
                DB::raw('COALESCE(SUM(amount), 0) as total_earned'),
                DB::raw('COALESCE(SUM(CASE WHEN paid_at IS NOT NULL THEN amount ELSE 0 END), 0) as total_paid'),
            ])
            ->keyBy('user_id');

        // تاسك 216: الحوافز = مكافأة خطة الشهر المستحقة (بلغت هدفها)، صُرفت أم لا — كقاعدة
        // «المستحق» في تقرير الحوافز؛ من bonusAmount() لا من SQL موازٍ (تاسك 73/105).
        $incentives = IncentivePlan::query()
            ->tap($inBranches)
            ->where('period_year', $monthStart->year)
            ->where('period_month', $monthStart->month)
            ->get()
            ->groupBy('user_id')
            ->map(fn ($plans) => (float) $plans->filter->isTargetMet()->sum(fn (IncentivePlan $p) => $p->bonusAmount()))
            ->filter();

        // والحسومات بتاريخ الحسم داخل الشهر؛ المحذوف خارجها بنطاق SoftDeletes.
        $deductions = EmployeeDeduction::query()
            ->tap($inBranches)
            ->deductedBetween($monthStart, $monthEnd)
            ->groupBy('user_id')
            ->pluck(DB::raw('SUM(amount)'), 'user_id');

        // Rows come from the salaried staff too, so an employee without commission
        // this month still shows (and counts toward the salaries total).
        $employees = User::query()
            ->withTrashed()
            ->where(fn ($q) => $q
                ->whereIn('id', $ledger->keys()->merge($incentives->keys())->merge($deductions->keys()))
                ->orWhere(fn ($q) => $q
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->where('salary', '>', 0)
                    ->tap($inBranches)))
            ->orderBy('name')
            ->get(['id', 'name', 'salary'])
            ->map(function (User $user) use ($ledger, $incentives, $deductions) {
                $row = $ledger->get($user->id);
                $earned = (float) ($row->total_earned ?? 0);
                $paid = (float) ($row->total_paid ?? 0);
                $salary = (float) $user->salary;
                $incentive = (float) $incentives->get($user->id, 0);
                $deducted = (float) $deductions->get($user->id, 0);

                return [
                    'userId' => $user->id,
                    'userName' => $user->name,
                    'salary' => $salary,
                    'totalEarned' => $earned,
                    'totalPaid' => $paid,
                    'pending' => max(0, $earned - $paid),
                    'incentives' => $incentive,
                    'deductions' => $deducted,
                    'netTotal' => round($salary + $earned + $incentive - $deducted, 2),
                ];
            })
            ->values();

        // The payment log has its own filters (task 183), apart from `month` which drives the table above.
        $paymentsQuery = CommissionPayment::query()->tap($inBranches);

        $paymentEmployees = User::query()
            ->withTrashed()
            ->whereIn('id', (clone $paymentsQuery)->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['value' => (string) $user->id, 'label' => $user->name]);

        $isoDate = fn (string $key) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input($key))
            ? $request->input($key)
            : null;

        $payments = $paymentsQuery
            ->with(['user', 'paidBy'])
            ->when($request->integer('payments_user'), fn ($q, $userId) => $q->where('user_id', $userId))
            ->when($isoDate('payments_from'), fn ($q, $from) => $q->whereDate('paid_at', '>=', $from))
            ->when($isoDate('payments_to'), fn ($q, $to) => $q->whereDate('paid_at', '<=', $to))
            ->latest('paid_at')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('commissions/index', [
            'employees' => $employees,
            'summary' => [
                'totalEarned' => (float) $employees->sum('totalEarned'),
                'totalPaid' => (float) $employees->sum('totalPaid'),
                'pending' => (float) $employees->sum('pending'),
                'totalSalaries' => round((float) $employees->sum('salary'), 2),
                'totalNet' => round((float) $employees->sum('netTotal'), 2),
            ],
            'payments' => CommissionPaymentResource::collection($payments),
            'branches' => $isSuper
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'isSuperAdmin' => $isSuper,
            'paymentEmployees' => $paymentEmployees,
            'filters' => [
                'branch' => implode(',', $isSuper ? $branchIds : []),
                'month' => $month,
                'paymentsUser' => $request->input('payments_user'),
                'paymentsFrom' => $isoDate('payments_from'),
                'paymentsTo' => $isoDate('payments_to'),
            ],
        ]);
    }

    public function pay(PayCommissionRequest $request, PayCommissionAction $action): RedirectResponse
    {
        $employee = User::query()->findOrFail($request->integer('user_id'));

        Gate::authorize('pay', [CommissionPayment::class, $employee]);

        $payment = $action->handle($request->validated(), $request->user());

        $payment->user?->notify(new CommissionPaidNotification($payment));

        return back(fallback: route('commissions.index'))->with('success', 'تم صرف العمولة بنجاح');
    }
}
