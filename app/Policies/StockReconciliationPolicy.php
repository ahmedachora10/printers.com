<?php

namespace App\Policies;

use App\Models\StockReconciliation;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class StockReconciliationPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant();
    }

    public function view(User $user, StockReconciliation $reconciliation): bool
    {
        return ($user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant())
            && ($user->roleName->isSuperAdmin() || $user->branchId === $reconciliation->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function update(User $user, StockReconciliation $reconciliation): bool
    {
        return ($user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin())
            && ($user->roleName->isSuperAdmin() || $user->branchId === $reconciliation->branch_id);
    }

    public function complete(User $user, StockReconciliation $reconciliation): bool
    {
        return $this->update($user, $reconciliation);
    }

    public function delete(User $user, StockReconciliation $reconciliation): bool
    {
        return $this->update($user, $reconciliation);
    }
}
