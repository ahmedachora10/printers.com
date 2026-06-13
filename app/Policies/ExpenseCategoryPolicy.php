<?php

namespace App\Policies;

use App\Models\ExpenseCategory;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ExpenseCategoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function view(User $user, ExpenseCategory $expenseCategory): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function update(User $user, ExpenseCategory $expenseCategory): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function delete(User $user, ExpenseCategory $expenseCategory): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function restore(User $user, ExpenseCategory $expenseCategory): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function forceDelete(User $user, ExpenseCategory $expenseCategory): bool
    {
        return $user->roleName->isSuperAdmin();
    }
}
