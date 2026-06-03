<?php

namespace App\Http\Controllers;

use App\Actions\User\CreateUserAction;
use App\Actions\User\DeleteUserAction;
use App\Actions\User\UpdateUserAction;
use App\Enums\Roles;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\User\UserResource;
use App\Models\Branch;
use App\Models\CommissionLedger;
use App\Models\ProductInvoice;
use App\Models\ServiceInvoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $actor = Auth::user();
        $isSuper = $actor->roleName->isSuperAdmin();

        $users = User::query()
            ->with(['roles', 'branch'])
            ->when(! $isSuper, fn ($q) => $q
                ->where('branch_id', $actor->branchId)
                ->whereHas('roles', fn ($r) => $r->whereNotIn('name', [
                    Roles::SUPER_ADMIN->value,
                    Roles::BRANCH_ADMIN->value,
                ])))
            ->when($request->filled('search'), fn ($q) => $q->where(function ($q) use ($request) {
                $search = $request->input('search');
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('username', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            }))
            ->when($request->filled('role'), fn ($q) => $q->whereHas('roles', fn ($r) => $r->where('name', $request->input('role'))))
            ->when($request->filled('status'), fn ($q) => $q->where('is_active', (bool) $request->input('status')))
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        return Inertia::render('users/index', [
            'users' => UserResource::collection($users),
            'roles' => $this->assignableRoles($isSuper),
            'branches' => $isSuper
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'isSuperAdmin' => $isSuper,
            'filters' => [
                'search' => $request->input('search'),
                'role' => $request->input('role'),
                'status' => $request->input('status'),
            ],
        ]);
    }

    public function show(User $user): Response
    {
        Gate::authorize('view', $user);

        $user->load(['roles', 'branch']);

        $isSuper = Auth::user()->roleName->isSuperAdmin();

        return Inertia::render('users/show', [
            'user' => new UserResource($user),
            'commissionSummary' => $this->commissionSummary($user),
            'salesSummary' => $this->salesSummary($user),
            'commissionHistory' => $this->recentCommissionLedger($user),
            'invoiceHistory' => $this->recentInvoices($user),
            'roles' => $this->assignableRoles($isSuper),
            'branches' => $isSuper
                ? Branch::query()->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            'isSuperAdmin' => $isSuper,
        ]);
    }

    public function store(StoreUserRequest $request, CreateUserAction $action): RedirectResponse
    {
        Gate::authorize('create', User::class);

        $action->handle($request->validated());

        return to_route('users.index')->with('success', 'تم إضافة المستخدم بنجاح');
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUserAction $action): RedirectResponse
    {
        Gate::authorize('update', $user);

        $action->handle($user, $request->validated());

        return to_route('users.index')->with('success', 'تم تحديث المستخدم بنجاح');
    }

    public function destroy(User $user, DeleteUserAction $action): RedirectResponse
    {
        Gate::authorize('delete', $user);

        $action->handle($user);

        return to_route('users.index')->with('success', 'تم حذف المستخدم بنجاح');
    }

    public function toggleStatus(User $user, UpdateUserAction $action): RedirectResponse
    {
        Gate::authorize('update', $user);

        $action->handle($user, ['is_active' => ! $user->is_active]);

        return back()->with('success', 'تم تحديث حالة المستخدم بنجاح');
    }

    /**
     * Aggregate the append-only commission ledger for a user.
     *
     * @return array{totalEarned: float, totalPaid: float, pending: float, tahazirEarned: float, standardEarned: float}
     */
    private function commissionSummary(User $user): array
    {
        $row = CommissionLedger::query()
            ->where('user_id', $user->id)
            ->selectRaw(
                'COALESCE(SUM(amount), 0) as total_earned,
                COALESCE(SUM(CASE WHEN paid_at IS NOT NULL THEN amount ELSE 0 END), 0) as total_paid,
                COALESCE(SUM(CASE WHEN is_tahazir = 1 THEN amount ELSE 0 END), 0) as tahazir_earned,
                COALESCE(SUM(CASE WHEN is_tahazir = 0 THEN amount ELSE 0 END), 0) as standard_earned'
            )
            ->first();

        $totalEarned = (float) ($row->total_earned ?? 0);
        $totalPaid = (float) ($row->total_paid ?? 0);

        return [
            'totalEarned' => $totalEarned,
            'totalPaid' => $totalPaid,
            'pending' => max(0, $totalEarned - $totalPaid),
            'tahazirEarned' => (float) ($row->tahazir_earned ?? 0),
            'standardEarned' => (float) ($row->standard_earned ?? 0),
        ];
    }

    /**
     * Counts and totals of invoices created by the user (excluding cancelled).
     *
     * @return array{serviceCount: int, serviceTotal: float, productCount: int, productTotal: float}
     */
    private function salesSummary(User $user): array
    {
        $summary = [
            'serviceCount' => 0,
            'serviceTotal' => 0.0,
            'productCount' => 0,
            'productTotal' => 0.0,
        ];

        /** @var array<class-string<Model>, string> $sources */
        $sources = [
            ServiceInvoice::class => 'service',
            ProductInvoice::class => 'product',
        ];

        foreach ($sources as $model => $type) {
            if (! Schema::hasTable((new $model)->getTable())) {
                continue;
            }

            $row = $model::query()
                ->where('user_id', $user->id)
                ->where('status', '<>', 'cancelled')
                ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(total_amount), 0) as total')
                ->first();

            $summary[$type.'Count'] = (int) ($row->cnt ?? 0);
            $summary[$type.'Total'] = (float) ($row->total ?? 0);
        }

        return $summary;
    }

    /**
     * Latest invoices created by the user, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function recentInvoices(User $user): array
    {
        $invoices = [];

        /** @var array<class-string<Model>, string> $sources */
        $sources = [
            ServiceInvoice::class => 'service',
            ProductInvoice::class => 'product',
        ];

        foreach ($sources as $model => $type) {
            if (! Schema::hasTable((new $model)->getTable())) {
                continue;
            }

            $rows = $model::query()
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(['id', 'invoice_number', 'total_amount', 'status', 'created_at'])
                ->map(fn ($r) => array_merge($r->toArray(), ['type' => $type]))
                ->all();

            $invoices = array_merge($invoices, $rows);
        }

        usort($invoices, fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return array_slice($invoices, 0, 10);
    }

    /**
     * Latest commission ledger entries for the user, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function recentCommissionLedger(User $user): array
    {
        return CommissionLedger::query()
            ->where('user_id', $user->id)
            ->orderByDesc('earned_at')
            ->limit(10)
            ->get(['id', 'amount', 'is_tahazir', 'source_type', 'earned_at', 'paid_at'])
            ->toArray();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function assignableRoles(bool $isSuper): array
    {
        $roles = $isSuper
            ? Roles::cases()
            : [Roles::ACCOUNTANT, Roles::EMPLOYEE, Roles::AGENT];

        return array_map(fn (Roles $role) => [
            'value' => $role->value,
            'label' => $role->label(),
        ], $roles);
    }
}
