<?php

namespace App\Policies;

use App\Models\CatalogPrice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * تاسك 47: the price list is per branch. The super admin owns the general
 * prices and may touch any branch's; the branch admin owns their branch's
 * rows and nothing else — not another branch's, and not the general row they
 * are overriding (deleting that would change every other branch's list).
 */
class CatalogPricePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function view(User $user, CatalogPrice $price): bool
    {
        return $user->roleName->isSuperAdmin()
            || ($user->roleName->isBranchAdmin() && $price->branch_id === null)
            || $this->ownsBranchRow($user, $price);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, CatalogPrice $price): bool
    {
        return $user->roleName->isSuperAdmin() || $this->ownsBranchRow($user, $price);
    }

    public function delete(User $user, CatalogPrice $price): bool
    {
        return $this->update($user, $price);
    }

    private function ownsBranchRow(User $user, CatalogPrice $price): bool
    {
        return $user->roleName->isBranchAdmin()
            && $price->branch_id !== null
            && $price->branch_id === $user->branch_id;
    }
}
