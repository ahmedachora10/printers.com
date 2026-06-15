<?php

namespace App\Policies;

use App\Enums\IncentivePlanStatusEnum;
use App\Models\IncentivePlan;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class IncentivePlanPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, IncentivePlan $plan): bool
    {
        // A paid plan is settled and immutable.
        return $plan->status !== IncentivePlanStatusEnum::Paid && $this->withinScope($user, $plan);
    }

    public function delete(User $user, IncentivePlan $plan): bool
    {
        return $plan->status !== IncentivePlanStatusEnum::Paid && $this->withinScope($user, $plan);
    }

    public function pay(User $user, IncentivePlan $plan): bool
    {
        return $this->withinScope($user, $plan);
    }

    /**
     * Super-admins act across branches; branch-admins only within their own.
     */
    private function withinScope(User $user, IncentivePlan $plan): bool
    {
        if ($user->roleName->isSuperAdmin()) {
            return true;
        }

        return $user->roleName->isBranchAdmin() && $plan->branch_id === $user->branchId;
    }
}
