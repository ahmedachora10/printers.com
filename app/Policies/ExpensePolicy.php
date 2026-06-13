<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ExpensePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant();
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->viewAny($user);
    }

    public function restore(User $user, Expense $expense): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return $user->roleName->isSuperAdmin();
    }
}
