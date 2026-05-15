<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProductPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant();
    }

    public function view(User $user, Product $product): bool
    {
        return ($user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant())
            && ($user->roleName->isSuperAdmin() || $user->branch_id === $product->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function update(User $user, Product $product): bool
    {
        return ($user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin())
            && ($user->roleName->isSuperAdmin() || $user->branch_id === $product->branch_id);
    }

    public function delete(User $user, Product $product): bool
    {
        return ($user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin())
            && ($user->roleName->isSuperAdmin() || $user->branch_id === $product->branch_id);
    }

    public function restore(User $user, Product $product): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return $user->roleName->isSuperAdmin();
    }
}
