<?php

namespace App\Policies;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CouponPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function view(User $user, Coupon $coupon): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchManager->id === $coupon->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function update(User $user, Coupon $coupon): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchManager->id === $coupon->branch_id);
    }

    public function delete(User $user, Coupon $coupon): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $user->branchManager->id === $coupon->branch_id);
    }

    public function restore(User $user, Coupon $coupon): bool
    {
        return $user->roleName->isSuperAdmin();
    }

    public function forceDelete(User $user, Coupon $coupon): bool
    {
        return $user->roleName->isSuperAdmin();
    }
}
