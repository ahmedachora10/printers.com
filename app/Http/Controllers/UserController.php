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
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', User::class);

        $actor = auth()->user();
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

        return to_route('users.index')->with('success', 'تم تحديث حالة المستخدم بنجاح');
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
