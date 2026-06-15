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

        $employees = CommissionLedger::query()
            ->join('users', 'users.id', '=', 'commission_ledger.user_id')
            ->when($branchId, fn ($q) => $q->where('commission_ledger.branch_id', $branchId))
            ->groupBy('commission_ledger.user_id', 'users.name')
            ->orderBy('users.name')
            ->toBase()
            ->get([
                'commission_ledger.user_id as user_id',
                'users.name as user_name',
                DB::raw('COALESCE(SUM(amount), 0) as total_earned'),
                DB::raw('COALESCE(SUM(CASE WHEN paid_at IS NOT NULL THEN amount ELSE 0 END), 0) as total_paid'),
                DB::raw('COALESCE(SUM(CASE WHEN is_tahazir = 1 THEN amount ELSE 0 END), 0) as tahazir_earned'),
            ])
            ->map(function ($row) {
                $earned = (float) $row->total_earned;
                $paid = (float) $row->total_paid;

                return [
                    'userId' => (int) $row->user_id,
                    'userName' => $row->user_name,
                    'totalEarned' => $earned,
                    'totalPaid' => $paid,
                    'pending' => max(0, $earned - $paid),
                    'tahazirEarned' => (float) $row->tahazir_earned,
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
            ],
            'payments' => CommissionPaymentResource::collection($payments),
            'branches' => $isSuper
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'isSuperAdmin' => $isSuper,
            'filters' => [
                'branch' => $request->input('branch'),
            ],
        ]);
    }

    public function pay(PayCommissionRequest $request, PayCommissionAction $action): RedirectResponse
    {
        $employee = User::query()->findOrFail($request->integer('user_id'));

        Gate::authorize('pay', [CommissionPayment::class, $employee]);

        $payment = $action->handle($request->validated(), $request->user());

        $payment->user?->notify(new CommissionPaidNotification($payment));

        return to_route('commissions.index')->with('success', 'تم صرف العمولة بنجاح');
    }
}
