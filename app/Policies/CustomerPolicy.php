<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CustomerPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant()
            || $user->roleName->isEmployee();
    }

    public function view(User $user, Customer $customer): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        $branchId = $user->roleName->isBranchAdmin()
            ? $user->branchManager?->id
            : $user->branch_id;

        return $branchId === $customer->branch_id;
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant()
            || $user->roleName->isEmployee();
    }

    public function update(User $user, Customer $customer): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        $branchId = $user->roleName->isBranchAdmin()
            ? $user->branchManager?->id
            : $user->branch_id;

        return $branchId === $customer->branch_id
            && ($user->roleName->isBranchAdmin() || $user->roleName->isAccountant());
    }

    public function delete(User $user, Customer $customer): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        return $user->roleName->isBranchAdmin()
            && $user->branchManager?->id === $customer->branch_id;
    }

    public function merge(User $user, Customer $customer): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        return $user->roleName->isBranchAdmin()
            && $user->branchManager?->id === $customer->branch_id;
    }

    public function restore(User $user, Customer $customer): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function forceDelete(User $user, Customer $customer): bool
    {
        return $user->roleName->isSuperAdmin();
    }
}
