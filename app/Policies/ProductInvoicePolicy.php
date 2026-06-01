<?php

namespace App\Policies;

use App\Models\ProductInvoice;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ProductInvoicePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant();
    }

    public function view(User $user, ProductInvoice $invoice): bool
    {
        return $this->viewAny($user)
            && ($user->roleName->isSuperAdmin() || $user->branchId === $invoice->branch_id);
    }

    public function create(User $user): bool
    {
        return $user->roleName->isSuperAdmin()
            || $user->roleName->isBranchAdmin()
            || $user->roleName->isAccountant();
    }
}
