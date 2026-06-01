<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class StockMovementPolicy
{
    use HandlesAuthorization;

    /**
     * Viewing the ledger is a read-only audit; accountants may view too.
     */
    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant();
    }

    /**
     * Recording manual movements (opening stock / adjustments) is admin-only.
     */
    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }
}
