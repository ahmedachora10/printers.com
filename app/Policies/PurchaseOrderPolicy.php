<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class PurchaseOrderPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant();
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return ($user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant())
            && ($user->roleName->isSuperAdmin() || $user->branch_id === $purchaseOrder->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin();
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return ($user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin())
            && ($user->roleName->isSuperAdmin() || $user->branch_id === $purchaseOrder->branch_id);
    }

    public function receive(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->update($user, $purchaseOrder);
    }

    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return ($user->roleName->isSuperAdmin() || $user->roleName->isBranchAdmin())
            && ($user->roleName->isSuperAdmin() || $user->branch_id === $purchaseOrder->branch_id);
    }
}
