<?php

namespace App\Http\Controllers;

use App\Actions\Commission\PayCommissionAction;
use App\Http\Requests\Commission\PayCommissionRequest;
use App\Http\Resources\Commission\CommissionPaymentResource;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\CommissionPayment;
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
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', CommissionPayment::class);

        $actor = $request->user();
        $isSuper = $actor->roleName->isSuperAdmin();

        // Super-admin may scope to a chosen branch; everyone else is locked to their own.
        $branchId = $isSuper
            ? ($request->filled('branch') ? (int) $request->input('branch') : null)
            : $actor->branchId;

        // Salary is monthly, so the ledger is summed over one month (task 134).
        $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $request->input('month'))
            ? $request->input('month')
            : now()->format('Y-m');
        $monthStart = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $ledger = CommissionLedger::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('earned_at', [$monthStart, $monthEnd])
            ->groupBy('user_id')
            ->toBase()
            ->get([
                'user_id',
                DB::raw('COALESCE(SUM(amount), 0) as total_earned'),
                DB::raw('COALESCE(SUM(CASE WHEN paid_at IS NOT NULL THEN amount ELSE 0 END), 0) as total_paid'),
                DB::raw('COALESCE(SUM(CASE WHEN is_tahazir = 1 THEN amount ELSE 0 END), 0) as tahazir_earned'),
            ])
            ->keyBy('user_id');

        // Rows come from the salaried staff too, so an employee without commission
        // this month still shows (and counts toward the salaries total).
        $employees = User::query()
            ->withTrashed()
            ->where(fn ($q) => $q
                ->whereIn('id', $ledger->keys())
                ->orWhere(fn ($q) => $q
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->where('salary', '>', 0)
                    ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))))
            ->orderBy('name')
            ->get(['id', 'name', 'salary'])
            ->map(function (User $user) use ($ledger) {
                $row = $ledger->get($user->id);
                $earned = (float) ($row->total_earned ?? 0);
                $paid = (float) ($row->total_paid ?? 0);
                $salary = (float) $user->salary;

                return [
                    'userId' => $user->id,
                    'userName' => $user->name,
                    'salary' => $salary,
                    'totalEarned' => $earned,
                    'totalPaid' => $paid,
                    'pending' => max(0, $earned - $paid),
                    'tahazirEarned' => (float) ($row->tahazir_earned ?? 0),
                    'salaryPlusCommission' => round($salary + $earned, 2),
                ];
            })
            ->values();

        $payments = CommissionPayment::query()
            ->with(['user', 'paidBy'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
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
                'totalSalariesPlusCommissions' => round((float) $employees->sum('salaryPlusCommission'), 2),
            ],
            'payments' => CommissionPaymentResource::collection($payments),
            'branches' => $isSuper
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'isSuperAdmin' => $isSuper,
            'filters' => [
                'branch' => $request->input('branch'),
                'month' => $month,
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
