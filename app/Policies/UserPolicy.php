<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->roleName->isSuperAdmin() || $this->managesInBranch($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->roleName->isSuperAdmin() || $this->managesInBranch($user, $model);
    }

    public function delete(User $user, User $model): bool
    {
        if ($user->id === $model->id) {
            return false;
        }

        return $user->roleName->isSuperAdmin() || $this->managesInBranch($user, $model);
    }

    public function restore(User $user, User $model): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function forceDelete(User $user, User $model): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    /**
     * A branch-admin may manage staff (accountant/employee/agent) within their own branch,
     * but never other super-admins or branch-admins.
     */
    private function managesInBranch(User $user, User $model): bool
    {
        if (! $user->roleName->isBranchAdmin()) {
            return false;
        }

        $targetRole = $model->roles()->first()?->name;

        if (in_array($targetRole, ['super-admin', 'branch-admin'], true)) {
            return false;
        }

        return $user->branchId !== null && $model->branch_id === $user->branchId;
    }
}
